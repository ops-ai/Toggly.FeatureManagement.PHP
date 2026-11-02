<?php

namespace Toggly\FeatureManagement\Tests\Unit\Laravel\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\SecureFeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\FeatureManagement\Core\UsageStatsProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Telemetry\FeatureUsageScope;
use Toggly\Laravel\Attributes\FeatureUsage;
use Toggly\Laravel\Middleware\FeatureGateAttributeMiddleware;

/**
 * Exercises FeatureUsage attribute handling in FeatureGateAttributeMiddleware.
 */
class FeatureGateAttributeMiddlewareTest extends TestCase
{
    public function testRecordsUsageScopeOnSuccessfulResponse(): void
    {
        $usage = $this->realUsageProvider();
        $middleware = $this->middleware($usage);
        $request = $this->requestFor(FeatureUsageSuccessController::class, 'index');

        $response = $middleware->handle($request, static function () {
            usleep(2000);
            return new Response('ok', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        $stats = $this->enabledStats($usage, 'Checkout');
        $this->assertSame(1, $stats['usedCount']);
        $this->assertSame(0, $stats['checkCount']);
        $this->assertSame(0, $stats['errorCount']);
        $this->assertGreaterThan(0, $stats['durationSumMs']);
    }

    public function testRecordsFailureOnExceptionAndRethrows(): void
    {
        $usage = $this->realUsageProvider();
        $middleware = $this->middleware($usage);
        $request = $this->requestFor(FeatureUsageSuccessController::class, 'index');

        try {
            $middleware->handle($request, static function () {
                usleep(2000);
                throw new RuntimeException('boom');
            });
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $stats = $this->enabledStats($usage, 'Checkout');
        $this->assertSame(1, $stats['usedCount']);
        $this->assertSame(0, $stats['checkCount']);
        $this->assertSame(1, $stats['errorCount']);
        $this->assertGreaterThan(0, $stats['errorDurationSumMs']);
    }

    public function testRecordsFailureOnFiveHundredResponse(): void
    {
        $usage = $this->realUsageProvider();
        $middleware = $this->middleware($usage);
        $request = $this->requestFor(FeatureUsageSuccessController::class, 'index');

        $response = $middleware->handle($request, static function () {
            usleep(2000);
            return new Response('error', 503);
        });

        $this->assertSame(503, $response->getStatusCode());
        $stats = $this->enabledStats($usage, 'Checkout');
        $this->assertSame(1, $stats['usedCount']);
        $this->assertSame(0, $stats['checkCount']);
        $this->assertSame(1, $stats['errorCount']);
        $this->assertGreaterThan(0, $stats['errorDurationSumMs']);
    }

    public function testDoesNotCallIsEnabledForFeatureUsage(): void
    {
        $usage = $this->realUsageProvider();
        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->never())->method('isEnabled');

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureUsageSuccessController::class, 'index');

        $middleware->handle($request, static function () {
            return new Response('ok', 200);
        });

        $stats = $this->enabledStats($usage, 'Checkout');
        $this->assertSame(1, $stats['usedCount']);
        $this->assertSame(0, $stats['checkCount']);
    }

    public function testLegacyCoreFallsBackToRecordUsageOnly(): void
    {
        $legacy = new class implements UsageStatsProviderInterface {
            /** @var list<string> */
            public array $recorded = [];

            public function recordCheck(string $featureKey, bool $allowed): void
            {
            }

            public function recordUsageWithContext(string $featureKey, $context, bool $allowed): void
            {
            }

            public function recordUsage(string $featureKey, string $variant = 'enabled'): void
            {
                $this->recorded[] = $featureKey;
            }

            public function use(string $featureKey, callable $action, string $variant = 'enabled')
            {
                return $action(FeatureUsageScope::noop());
            }

            public function recordView(string $featureKey): void
            {
            }

            public function recordDefinitionCacheHit(): void
            {
            }

            public function recordDefinitionCacheMiss(): void
            {
            }
        };

        $this->assertFalse(method_exists($legacy, 'beginUsage'));

        $middleware = $this->middleware($legacy);
        $request = $this->requestFor(FeatureUsageSuccessController::class, 'index');

        $response = $middleware->handle($request, static function () {
            return new Response('ok', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Checkout'], $legacy->recorded);
    }

    public function testCollectsClassAndMethodUsageFeaturesWithoutDuplicateKeys(): void
    {
        $usage = $this->realUsageProvider();
        $middleware = $this->middleware($usage);
        $request = $this->requestFor(FeatureUsageBothLevelsController::class, 'show');

        $middleware->handle($request, static function () {
            usleep(2000);
            return new Response('ok', 200);
        });

        $checkout = $this->enabledStats($usage, 'Checkout');
        $billing = $this->enabledStats($usage, 'Billing');
        $this->assertSame(1, $checkout['usedCount']);
        $this->assertSame(1, $billing['usedCount']);
        $this->assertSame(0, $checkout['checkCount']);
        $this->assertSame(0, $billing['checkCount']);
    }

    public function testPassesThroughWhenNoUsageAttributes(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $usage->expects($this->never())->method('recordUsage');
        $usage->expects($this->never())->method('use');

        $middleware = $this->middleware($usage);
        $request = $this->requestFor(PlainController::class, 'index');

        $response = $middleware->handle($request, static function () {
            return new Response('plain', 200);
        });

        $this->assertSame('plain', $response->getContent());
    }

    private function middleware(UsageStatsProviderInterface $usage): FeatureGateAttributeMiddleware
    {
        $featureManager = new FeatureManager(
            $this->createMock(FeatureProviderInterface::class),
            $usage,
            $this->createMock(SecureFeatureProviderInterface::class)
        );

        return new FeatureGateAttributeMiddleware($featureManager, $usage);
    }

    private function realUsageProvider(): UsageStatsProvider
    {
        return new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $this->createMock(TogglyHttpClient::class),
            null,
            new NullLogger(),
            null
        );
    }

    /**
     * @return array{checkCount: int, usedCount: int, errorCount: int, durationSumMs: int, errorDurationSumMs: int}
     */
    private function enabledStats(UsageStatsProvider $usage, string $featureKey): array
    {
        $peek = $usage->peekPayload();
        $this->assertNotNull($peek);
        foreach ($peek['stats'] as $stat) {
            if (($stat['feature'] ?? null) === $featureKey) {
                return $stat['variantStats']['enabled'];
            }
        }
        $this->fail('missing stats for ' . $featureKey);
    }

    private function requestFor(string $controllerClass, string $method): Request
    {
        $request = Request::create('/demo', 'GET');
        $route = new Route(['GET'], '/demo', [
            'controller' => $controllerClass . '@' . $method,
        ]);
        $request->setRouteResolver(static function () use ($route) {
            return $route;
        });

        return $request;
    }
}

#[FeatureUsage('Checkout')]
class FeatureUsageSuccessController
{
    public function index(): Response
    {
        return new Response('ok');
    }
}

#[FeatureUsage('Checkout')]
class FeatureUsageBothLevelsController
{
    #[FeatureUsage(['Checkout', 'Billing'])]
    public function show(): Response
    {
        return new Response('ok');
    }
}

class PlainController
{
    public function index(): Response
    {
        return new Response('plain');
    }
}
