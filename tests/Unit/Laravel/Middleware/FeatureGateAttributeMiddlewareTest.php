<?php

namespace Toggly\FeatureManagement\Tests\Unit\Laravel\Middleware;

require_once dirname(__DIR__, 3) . '/fixtures/LaravelHttpHelpers.php';

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\SecureFeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\FeatureManagement\Core\UsageStatsProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Telemetry\FeatureUsageScope;
use Toggly\Laravel\Attributes\FeatureGate;
use Toggly\Laravel\Attributes\FeatureUsage;
use Toggly\Laravel\Middleware\FeatureGateAttributeMiddleware;

/**
 * Exercises FeatureUsage / FeatureGate attribute handling in FeatureGateAttributeMiddleware.
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

    public function testPassesThroughWhenRouteIsMissing(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $usage->expects($this->never())->method('recordUsage');

        $middleware = $this->middleware($usage);
        $request = Request::create('/demo', 'GET');
        $request->setRouteResolver(static fn () => null);

        $response = $middleware->handle($request, static function () {
            return new Response('no-route', 200);
        });

        $this->assertSame('no-route', $response->getContent());
    }

    public function testPassesThroughWhenControllerActionIsNotAString(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $usage->expects($this->never())->method('recordUsage');

        $middleware = $this->middleware($usage);
        $request = Request::create('/demo', 'GET');
        $route = new Route(['GET'], '/demo', ['uses' => static fn () => 'ok']);
        $request->setRouteResolver(static fn () => $route);

        $response = $middleware->handle($request, static function () {
            return new Response('closure', 200);
        });

        $this->assertSame('closure', $response->getContent());
    }

    public function testPassesThroughWhenControllerClassDoesNotExist(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $usage->expects($this->never())->method('recordUsage');

        $middleware = $this->middleware($usage);
        $request = Request::create('/demo', 'GET');
        $route = new Route(['GET'], '/demo', [
            'controller' => 'Totally\\Missing\\Controller@index',
        ]);
        $request->setRouteResolver(static fn () => $route);

        $response = $middleware->handle($request, static function () {
            return new Response('missing', 200);
        });

        $this->assertSame('missing', $response->getContent());
    }

    public function testRunsNextWithoutRecordingWhenUsageProviderIsUnavailable(): void
    {
        $featureManager = $this->createMock(FeatureManager::class);
        $middleware = new FeatureGateAttributeMiddleware($featureManager, null);
        $request = $this->requestFor(FeatureUsageSuccessController::class, 'index');

        $response = $middleware->handle($request, static function () {
            return new Response('unscoped', 200);
        });

        $this->assertSame('unscoped', $response->getContent());
    }

    public function testFeatureGateAnyAllowsRequestAndRecordsUsage(): void
    {
        $usage = $this->realUsageProvider();
        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->once())
            ->method('isEnabled')
            ->with('Checkout', $this->callback(static function (array $context): bool {
                return ($context['ip'] ?? null) !== null;
            }))
            ->willReturn(true);

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureGateAnyController::class, 'index');

        $response = $middleware->handle($request, static function () {
            usleep(2000);
            return new Response('gated-ok', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        $stats = $this->enabledStats($usage, 'Checkout');
        $this->assertSame(1, $stats['usedCount']);
        $this->assertSame(0, $stats['checkCount']);
    }

    public function testFeatureGateAllRequiresEveryFeature(): void
    {
        $usage = $this->realUsageProvider();
        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->exactly(2))
            ->method('isEnabled')
            ->willReturnCallback(static function (string $feature): bool {
                return $feature === 'Alpha' || $feature === 'Beta';
            });

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureGateAllController::class, 'index');

        $response = $middleware->handle($request, static function () {
            return new Response('all-ok', 200);
        });

        $this->assertSame('all-ok', $response->getContent());
    }

    public function testFeatureGateDisabledRedirects(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $usage->expects($this->never())->method('recordUsage');

        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->once())->method('isEnabled')->willReturn(false);

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureGateRedirectController::class, 'index');

        $response = $middleware->handle($request, static function () {
            return new Response('should-not-run', 200);
        });

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.test/upgrade', $response->headers->get('Location'));
    }

    public function testFeatureGateDisabledAborts(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->once())->method('isEnabled')->willReturn(false);

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureGateAbortController::class, 'index');

        $this->expectException(HttpException::class);
        $middleware->handle($request, static function () {
            return new Response('should-not-run', 200);
        });
    }

    public function testFeatureGateMethodLevelBlocksBeforeUsage(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $usage->expects($this->never())->method('recordUsage');

        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->once())->method('isEnabled')->willReturn(false);

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureGateMethodController::class, 'index');

        $response = $middleware->handle($request, static function () {
            return new Response('blocked', 200);
        });

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testBuildContextIncludesAuthenticatedUserAndGroups(): void
    {
        $usage = $this->realUsageProvider();
        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->once())
            ->method('isEnabled')
            ->with('Checkout', $this->callback(static function (array $context): bool {
                return ($context['userId'] ?? null) === 42
                    && ($context['user_id'] ?? null) === 42
                    && ($context['groups'] ?? null) === ['admins']
                    && isset($context['ip']);
            }))
            ->willReturn(true);

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureGateAnyController::class, 'index');
        $request->setUserResolver(static function () {
            return new class {
                public int $id = 42;

                public function groups(): array
                {
                    return ['admins'];
                }
            };
        });

        $response = $middleware->handle($request, static function () {
            return new Response('user-ok', 200);
        });

        $this->assertSame('user-ok', $response->getContent());
    }

    public function testFeatureGateAllFailsWhenAnyFeatureDisabled(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->once())
            ->method('isEnabled')
            ->with('Alpha', $this->callback(static function ($value) { return is_array($value); }))
            ->willReturn(false);

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureGateAllController::class, 'index');

        $this->expectException(HttpException::class);
        $middleware->handle($request, static function () {
            return new Response('should-not-run', 200);
        });
    }

    public function testFeatureGateAnySucceedsOnSecondFeature(): void
    {
        $usage = $this->realUsageProvider();
        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->expects($this->exactly(2))
            ->method('isEnabled')
            ->willReturnCallback(static function (string $feature): bool {
                return $feature === 'Beta';
            });

        $middleware = new FeatureGateAttributeMiddleware($featureManager, $usage);
        $request = $this->requestFor(FeatureGateAnyMultiController::class, 'index');

        $response = $middleware->handle($request, static function () {
            return new Response('any-second', 200);
        });

        $this->assertSame('any-second', $response->getContent());
    }

    public function testResolvesUsageProviderFromAppContainerWhenNotInjected(): void
    {
        $usage = $this->realUsageProvider();
        $this->installTestApp(new class ($usage) {
            public function __construct(private UsageStatsProviderInterface $usage)
            {
            }

            public function bound(string $abstract): bool
            {
                return $abstract === UsageStatsProviderInterface::class;
            }

            public function make(string $abstract): UsageStatsProviderInterface
            {
                if ($abstract !== UsageStatsProviderInterface::class) {
                    throw new RuntimeException('unexpected abstract: ' . $abstract);
                }

                return $this->usage;
            }
        });

        try {
            $featureManager = $this->createMock(FeatureManager::class);
            $middleware = new FeatureGateAttributeMiddleware($featureManager, null);
            $request = $this->requestFor(FeatureUsageSuccessController::class, 'index');

            $response = $middleware->handle($request, static function () {
                usleep(2000);
                return new Response('from-app', 200);
            });

            $this->assertSame(200, $response->getStatusCode());
            $stats = $this->enabledStats($usage, 'Checkout');
            $this->assertSame(1, $stats['usedCount']);
        } finally {
            unset($GLOBALS['toggly_test_app']);
        }
    }

    public function testResolvesConcreteUsageProviderFromAppContainer(): void
    {
        $usage = $this->realUsageProvider();
        $this->installTestApp(new class ($usage) {
            public function __construct(private UsageStatsProvider $usage)
            {
            }

            public function bound(string $abstract): bool
            {
                return $abstract === UsageStatsProvider::class;
            }

            public function make(string $abstract): UsageStatsProvider
            {
                if ($abstract !== UsageStatsProvider::class) {
                    throw new RuntimeException('unexpected abstract: ' . $abstract);
                }

                return $this->usage;
            }
        });

        try {
            $featureManager = $this->createMock(FeatureManager::class);
            $middleware = new FeatureGateAttributeMiddleware($featureManager, null);
            $request = $this->requestFor(FeatureUsageSuccessController::class, 'index');

            $response = $middleware->handle($request, static function () {
                usleep(2000);
                return new Response('from-concrete', 200);
            });

            $this->assertSame(200, $response->getStatusCode());
            $stats = $this->enabledStats($usage, 'Checkout');
            $this->assertSame(1, $stats['usedCount']);
        } finally {
            unset($GLOBALS['toggly_test_app']);
        }
    }

    private function installTestApp(object $app): void
    {
        $GLOBALS['toggly_test_app'] = $app;

        if (!function_exists('app')) {
            eval('function app($abstract = null) {
                if (!isset($GLOBALS["toggly_test_app"])) {
                    throw new \\RuntimeException("test app unavailable");
                }
                return $GLOBALS["toggly_test_app"];
            }');
        }
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

#[FeatureGate('Checkout')]
#[FeatureUsage('Checkout')]
class FeatureGateAnyController
{
    public function index(): Response
    {
        return new Response('ok');
    }
}

#[FeatureGate(features: ['Alpha', 'Beta'], requirement: 'All')]
class FeatureGateAllController
{
    public function index(): Response
    {
        return new Response('ok');
    }
}

#[FeatureGate(features: ['Alpha', 'Beta'], requirement: 'Any')]
class FeatureGateAnyMultiController
{
    public function index(): Response
    {
        return new Response('ok');
    }
}

#[FeatureGate(features: 'Checkout', redirectTo: 'https://example.test/upgrade')]
class FeatureGateRedirectController
{
    public function index(): Response
    {
        return new Response('ok');
    }
}

#[FeatureGate(features: 'Checkout', statusCode: 403)]
class FeatureGateAbortController
{
    public function index(): Response
    {
        return new Response('ok');
    }
}

class FeatureGateMethodController
{
    #[FeatureGate(features: 'Checkout', redirectTo: 'https://example.test/method')]
    #[FeatureUsage('Checkout')]
    public function index(): Response
    {
        return new Response('ok');
    }
}
