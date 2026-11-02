<?php

namespace Toggly\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\Laravel\Attributes\FeatureGate as FeatureGateAttribute;
use Toggly\Laravel\Attributes\FeatureUsage as FeatureUsageAttribute;

/**
 * Middleware that automatically reads FeatureGate attributes from controllers and methods.
 *
 * This provides the same functionality as C#'s FeatureGate attribute system,
 * automatically gating routes based on attributes without needing to manually
 * add middleware to routes.
 */
class FeatureGateAttributeMiddleware
{
    private FeatureManager $featureManager;
    private ?UsageStatsProviderInterface $usageStatsProvider;

    public function __construct(
        FeatureManager $featureManager,
        ?UsageStatsProviderInterface $usageStatsProvider = null
    ) {
        $this->featureManager = $featureManager;
        $this->usageStatsProvider = $usageStatsProvider;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (!$route) {
            return $next($request);
        }

        $action = $route->getAction();
        $controller = $action['controller'] ?? null;

        if (!$controller || !is_string($controller)) {
            return $next($request);
        }

        [$controllerClass, $methodName] = explode('@', $controller, 2);

        if (!class_exists($controllerClass)) {
            return $next($request);
        }

        $reflection = new ReflectionClass($controllerClass);

        // Check controller-level attributes
        $controllerAttributes = $reflection->getAttributes(FeatureGateAttribute::class);
        foreach ($controllerAttributes as $attribute) {
            $featureGate = $attribute->newInstance();
            if (!$this->checkFeatureGate($featureGate, $request)) {
                return $this->handleDisabledFeature($featureGate, $request);
            }
        }

        // Check method-level attributes
        if ($reflection->hasMethod($methodName)) {
            $method = $reflection->getMethod($methodName);
            $methodAttributes = $method->getAttributes(FeatureGateAttribute::class);

            foreach ($methodAttributes as $attribute) {
                $featureGate = $attribute->newInstance();
                if (!$this->checkFeatureGate($featureGate, $request)) {
                    return $this->handleDisabledFeature($featureGate, $request);
                }
            }
        }

        $usageFeatures = $this->collectUsageFeatures($reflection, $methodName);

        if ($usageFeatures === []) {
            return $next($request);
        }

        return $this->runWithFeatureUsage($usageFeatures, static function () use ($next, $request) {
            return $next($request);
        });
    }

    /**
     * Collect FeatureUsage feature keys from method- and class-level attributes.
     *
     * @return list<string>
     */
    private function collectUsageFeatures(ReflectionClass $reflection, string $methodName): array
    {
        $features = [];

        if ($reflection->hasMethod($methodName)) {
            $method = $reflection->getMethod($methodName);
            foreach ($method->getAttributes(FeatureUsageAttribute::class) as $attribute) {
                foreach ($attribute->newInstance()->getFeatures() as $feature) {
                    $features[] = $feature;
                }
            }
        }

        foreach ($reflection->getAttributes(FeatureUsageAttribute::class) as $attribute) {
            foreach ($attribute->newInstance()->getFeatures() as $feature) {
                $features[] = $feature;
            }
        }

        return array_values(array_unique($features));
    }

    /**
     * Open a usage scope per feature around the controller action when the core
     * scope API is available; otherwise fall back to count-only recordUsage.
     *
     * @param list<string> $features
     * @param callable(): Response $action
     */
    private function runWithFeatureUsage(array $features, callable $action): Response
    {
        $usageStats = $this->resolveUsageStatsProvider();
        if ($usageStats === null) {
            return $action();
        }

        // Published core before FeatureUsageScope (e.g. v1.1.3) lacks beginUsage.
        if (!method_exists($usageStats, 'beginUsage')) {
            foreach ($features as $feature) {
                $usageStats->recordUsage($feature);
            }

            return $action();
        }

        $scopes = [];
        foreach ($features as $feature) {
            $scopes[] = $usageStats->beginUsage($feature);
        }

        try {
            $response = $action();
            if ($response->getStatusCode() >= 500) {
                foreach ($scopes as $scope) {
                    $scope->fail();
                }
            }

            return $response;
        } catch (Throwable $e) {
            foreach ($scopes as $scope) {
                $scope->fail();
            }
            throw $e;
        } finally {
            foreach ($scopes as $scope) {
                $scope->complete();
            }
        }
    }

    private function resolveUsageStatsProvider(): ?UsageStatsProviderInterface
    {
        if ($this->usageStatsProvider !== null) {
            return $this->usageStatsProvider;
        }

        if (function_exists('app')) {
            try {
                $app = app();
                if (method_exists($app, 'bound') && $app->bound(UsageStatsProviderInterface::class)) {
                    return $app->make(UsageStatsProviderInterface::class);
                }
                if (method_exists($app, 'bound') && $app->bound(\Toggly\FeatureManagement\Core\UsageStatsProvider::class)) {
                    return $app->make(\Toggly\FeatureManagement\Core\UsageStatsProvider::class);
                }
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Check if feature gate requirements are met
     */
    private function checkFeatureGate(FeatureGateAttribute $featureGate, Request $request): bool
    {
        $context = $this->buildContext($request);
        $features = $featureGate->getFeatures();
        $requirement = $featureGate->requirement;

        if ($requirement === 'All') {
            // All features must be enabled
            foreach ($features as $feature) {
                if (!$this->featureManager->isEnabled($feature, $context)) {
                    return false;
                }
            }
            return true;
        } else {
            // Any feature must be enabled
            foreach ($features as $feature) {
                if ($this->featureManager->isEnabled($feature, $context)) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * Handle disabled feature
     */
    private function handleDisabledFeature(FeatureGateAttribute $featureGate, Request $request): Response
    {
        if ($featureGate->redirectTo !== null) {
            return redirect($featureGate->redirectTo);
        }

        $statusCode = $featureGate->statusCode ?? 404;
        abort($statusCode, 'Feature not enabled');
    }

    /**
     * Build context from request
     */
    private function buildContext(Request $request): array
    {
        $context = [];

        if ($request->user()) {
            $context['userId'] = $request->user()->id;
            $context['user_id'] = $request->user()->id;

            // Add groups if available
            if (method_exists($request->user(), 'groups')) {
                $context['groups'] = $request->user()->groups();
            }
        }

        $context['ip'] = $request->ip();

        return $context;
    }
}
