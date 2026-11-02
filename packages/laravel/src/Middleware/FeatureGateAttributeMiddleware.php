<?php

namespace Toggly\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\FeatureManagement\Core\UsageStatsProvider;
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
        $controllerTarget = $this->resolveControllerTarget($request);
        if ($controllerTarget === null) {
            return $next($request);
        }

        [$reflection, $methodName] = $controllerTarget;
        $disabledGate = $this->findDisabledFeatureGate($reflection, $methodName, $request);
        if ($disabledGate !== null) {
            return $this->handleDisabledFeature($disabledGate, $request);
        }

        $usageFeatures = $this->collectUsageFeatures($reflection, $methodName);

        return $usageFeatures === []
            ? $next($request)
            : $this->runWithFeatureUsage($usageFeatures, static function () use ($next, $request) {
                return $next($request);
            });
    }

    /**
     * @return array{0: ReflectionClass, 1: string}|null
     */
    private function resolveControllerTarget(Request $request): ?array
    {
        $route = $request->route();
        $controller = $route ? ($route->getAction()['controller'] ?? null) : null;
        if (!$controller || !is_string($controller)) {
            return null;
        }

        [$controllerClass, $methodName] = explode('@', $controller, 2);
        if (!class_exists($controllerClass)) {
            return null;
        }

        return [new ReflectionClass($controllerClass), $methodName];
    }

    private function findDisabledFeatureGate(
        ReflectionClass $reflection,
        string $methodName,
        Request $request
    ): ?FeatureGateAttribute {
        $attributes = $reflection->getAttributes(FeatureGateAttribute::class);
        if ($reflection->hasMethod($methodName)) {
            $attributes = array_merge(
                $attributes,
                $reflection->getMethod($methodName)->getAttributes(FeatureGateAttribute::class)
            );
        }

        foreach ($attributes as $attribute) {
            $featureGate = $attribute->newInstance();
            if (!$this->checkFeatureGate($featureGate, $request)) {
                return $featureGate;
            }
        }

        return null;
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

        return $this->executeWithUsageScopes($usageStats, $features, $action);
    }

    /**
     * @param list<string> $features
     * @param callable(): Response $action
     */
    private function executeWithUsageScopes(
        UsageStatsProviderInterface $usageStats,
        array $features,
        callable $action
    ): Response {
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
        return $this->usageStatsProvider ?? $this->usageStatsProviderFromContainer();
    }

    private function usageStatsProviderFromContainer(): ?UsageStatsProviderInterface
    {
        $provider = null;

        if (function_exists('app')) {
            try {
                $app = app();
                if (method_exists($app, 'bound')) {
                    foreach ([UsageStatsProviderInterface::class, UsageStatsProvider::class] as $abstract) {
                        if ($app->bound($abstract)) {
                            $provider = $app->make($abstract);
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                $provider = null;
            }
        }

        return $provider;
    }

    /**
     * Check if feature gate requirements are met
     */
    private function checkFeatureGate(FeatureGateAttribute $featureGate, Request $request): bool
    {
        $context = $this->buildContext($request);
        $features = $featureGate->getFeatures();
        $allowed = false;

        if ($featureGate->requirement === 'All') {
            $allowed = true;
            foreach ($features as $feature) {
                if (!$this->featureManager->isEnabled($feature, $context)) {
                    $allowed = false;
                    break;
                }
            }
        } else {
            foreach ($features as $feature) {
                if ($this->featureManager->isEnabled($feature, $context)) {
                    $allowed = true;
                    break;
                }
            }
        }

        return $allowed;
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

            if (method_exists($request->user(), 'groups')) {
                $context['groups'] = $request->user()->groups();
            }
        }

        $context['ip'] = $request->ip();

        return $context;
    }
}
