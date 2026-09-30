<?php

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Core\MetricsRegistryService;
use Toggly\FeatureManagement\Core\MetricsService;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Telemetry\MetricsGrpcClient;

class MetricsServiceTest extends TestCase
{
    public function testFeatureLinkedMetricsAndContextCallsKeepTheirValues(): void
    {
        $features = $this->createMock(FeatureProviderInterface::class);
        $features->method('getFeaturesForMetric')->willReturn(['checkout', 'pricing']);
        $http = $this->createMock(TogglyHttpClient::class);
        $grpc = new class implements MetricsGrpcClient {
            public function sendMetrics(array $payload, array $metadata = []): void
            {
            }

            public function close(): void
            {
            }
        };

        $service = new MetricsService(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            $features,
            new MetricsRegistryService(),
            new NullLogger(),
            $grpc
        );
        $service->measure('revenue', 2.5);
        $service->measureWithContext('revenue', ['user' => 'u1'], 3.0);
        $service->incrementCounter('orders');
        $service->incrementCounterWithContext('orders', ['user' => 'u1'], 2.0);
        $service->observeWithContext('latency', ['user' => 'u1'], 9.0);

        $payload = $service->peekPayload();
        $this->assertNotNull($payload);
        $this->assertSame([
            ['metric' => 'revenue', 'variantValues' => ['enabled' => 5.5]],
            ['metric' => 'revenue', 'variantValues' => ['enabled' => 5.5], 'feature' => 'checkout'],
            ['metric' => 'revenue', 'variantValues' => ['enabled' => 5.5], 'feature' => 'pricing'],
        ], $payload['stats']);
        $this->assertSame([
            ['metric' => 'orders', 'variantValues' => ['enabled' => 3.0]],
            ['metric' => 'orders', 'variantValues' => ['enabled' => 3.0], 'feature' => 'checkout'],
            ['metric' => 'orders', 'variantValues' => ['enabled' => 3.0], 'feature' => 'pricing'],
        ], $payload['counters']);
        $this->assertCount(3, $payload['observations']);
        $this->assertSame('latency', $payload['observations'][0]['metric']);
        $this->assertSame(['enabled' => 9.0], $payload['observations'][0]['variantValues']);
        $this->assertSame('checkout', $payload['observations'][1]['feature']);
        $this->assertSame('pricing', $payload['observations'][2]['feature']);
    }

    public function testRegistryMetricsJoinDirectMetricsInOneGrpcFlush(): void
    {
        $features = $this->createMock(FeatureProviderInterface::class);
        $features->method('getFeaturesForMetric')->willReturn(null);
        $http = $this->createMock(TogglyHttpClient::class);
        $registry = new MetricsRegistryService();
        $registry->registerMeasurements(static function (): array {
            return ['revenue' => 4.0];
        });
        $registry->registerCounters(static function (): array {
            return ['orders' => 3.0];
        });
        $registry->registerObservations(static function (): array {
            return ['latency' => [1700000000, 8.0]];
        });
        $grpc = new class implements MetricsGrpcClient {
            public ?array $sent = null;

            public function sendMetrics(array $payload, array $metadata = []): void
            {
                $this->sent = $payload;
            }

            public function close(): void
            {
            }
        };

        $service = new MetricsService(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            $features,
            $registry,
            new NullLogger(),
            $grpc
        );
        $service->measure('revenue', 1.5);
        $service->incrementCounter('orders', 2.0);
        $service->flush();

        $this->assertNotNull($grpc->sent);
        $this->assertSame([['metric' => 'revenue', 'variantValues' => ['enabled' => 5.5]]], $grpc->sent['stats']);
        $this->assertSame([['metric' => 'orders', 'variantValues' => ['enabled' => 5.0]]], $grpc->sent['counters']);
        $this->assertSame('latency', $grpc->sent['observations'][0]['metric']);
        $this->assertSame(['seconds' => 1700000000, 'nanos' => 0], $grpc->sent['observations'][0]['time']);
        $this->assertSame(['enabled' => 8.0], $grpc->sent['observations'][0]['variantValues']);
        $this->assertNull($service->peekPayload());
    }

    public function testFailedGrpcSendRestoresMetricsAndNextFlushCombinesNewValues(): void
    {
        $features = $this->createMock(FeatureProviderInterface::class);
        $features->method('getFeaturesForMetric')->willReturn(['checkout']);
        $http = $this->createMock(TogglyHttpClient::class);
        $grpc = new class implements MetricsGrpcClient {
            public int $attempts = 0;
            public ?array $sent = null;

            public function sendMetrics(array $payload, array $metadata = []): void
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new \RuntimeException('temporary transport failure');
                }
                $this->sent = $payload;
            }

            public function close(): void
            {
            }
        };

        $service = new MetricsService(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            $features,
            new MetricsRegistryService(),
            new NullLogger(),
            $grpc
        );
        $service->measure('revenue', 2.0);
        $service->incrementCounter('orders', 1.0);
        $service->observe('latency', 7.0);
        $service->sendMetrics();

        $restored = $service->peekPayload();
        $this->assertNotNull($restored);
        $this->assertSame(['enabled' => 2.0], $restored['stats'][1]['variantValues']);
        $this->assertSame('checkout', $restored['stats'][1]['feature']);
        $this->assertSame(['enabled' => 1.0], $restored['counters'][1]['variantValues']);
        $this->assertSame('checkout', $restored['observations'][1]['feature']);
        $this->assertSame(['enabled' => 7.0], $restored['observations'][1]['variantValues']);

        $service->measure('revenue', 3.0);
        $service->incrementCounter('orders', 2.0);
        $service->sendMetrics();

        $this->assertSame(2, $grpc->attempts);
        $this->assertNotNull($grpc->sent);
        $this->assertSame(['enabled' => 5.0], $grpc->sent['stats'][1]['variantValues']);
        $this->assertSame(['enabled' => 3.0], $grpc->sent['counters'][1]['variantValues']);
        $this->assertSame('checkout', $grpc->sent['observations'][1]['feature']);
        $this->assertNull($service->peekPayload());
    }

    public function testHttpFallbackRebasesDefinitionsHostAndFormatsTimestamps(): void
    {
        if (\Toggly\FeatureManagement\Telemetry\GrpcClients::isAvailable()) {
            $this->markTestSkipped('gRPC available; HTTP fallback is not selected');
        }

        $captured = null;
        $response = $this->createMock(ResponseInterface::class);
        $metricsHttp = $this->createMock(TogglyHttpClient::class);
        $metricsHttp->method('post')->willReturnCallback(
            static function ($path, $payload, $retries) use (&$captured, $response): ResponseInterface {
                $captured = [$path, $payload, $retries];
                return $response;
            }
        );
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('getBaseUrl')->willReturn('https://definitions.toggly.io/');
        $http->method('withBaseUrl')->willReturnCallback(
            static function (string $baseUrl) use ($metricsHttp): TogglyHttpClient {
                self::assertSame('https://metrics.toggly.io/', $baseUrl);
                return $metricsHttp;
            }
        );
        $http->expects($this->never())->method('post');
        $features = $this->createMock(FeatureProviderInterface::class);
        $features->method('getFeaturesForMetric')->willReturn(null);

        $service = new MetricsService(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'base_url' => 'https://definitions.toggly.io/',
            ]),
            $http,
            $features,
            new MetricsRegistryService(),
            new NullLogger()
        );
        $beforeSend = time();
        $service->measure('revenue', 2.0);
        $service->observe('latency', 7.0);
        $service->sendMetrics();
        $afterSend = time();

        $this->assertNotNull($captured);
        $this->assertSame('api/metrics', $captured[0]);
        $this->assertSame(1, $captured[2]);
        $this->assertSame('app', $captured[1]['appKey']);
        $this->assertSame([['metric' => 'revenue', 'variantValues' => ['enabled' => 2.0]]], $captured[1]['stats']);
        $this->assertSame(['enabled' => 7.0], $captured[1]['observations'][0]['variantValues']);
        $this->assertIsString($captured[1]['time']);
        $this->assertGreaterThanOrEqual($beforeSend, strtotime($captured[1]['time']));
        $this->assertLessThanOrEqual($afterSend, strtotime($captured[1]['time']));
        $this->assertGreaterThanOrEqual($beforeSend, strtotime($captured[1]['observations'][0]['time']));
        $this->assertLessThanOrEqual($afterSend, strtotime($captured[1]['observations'][0]['time']));
        $this->assertNull($service->peekPayload());
    }

    public function testHttpFallbackKeepsCustomMetricsHost(): void
    {
        if (\Toggly\FeatureManagement\Telemetry\GrpcClients::isAvailable()) {
            $this->markTestSkipped('gRPC available; HTTP fallback is not selected');
        }

        $captured = null;
        $response = $this->createMock(ResponseInterface::class);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('getBaseUrl')->willReturn('https://metrics.example.test/');
        $http->expects($this->never())->method('withBaseUrl');
        $http->method('post')->willReturnCallback(
            static function ($path, $payload, $retries) use (&$captured, $response): ResponseInterface {
                $captured = [$path, $payload, $retries];
                return $response;
            }
        );
        $features = $this->createMock(FeatureProviderInterface::class);
        $features->method('getFeaturesForMetric')->willReturn(null);

        $service = new MetricsService(
            new TogglySettings([
                'app_key' => 'app',
                'base_url' => 'https://metrics.example.test/',
            ]),
            $http,
            $features,
            new MetricsRegistryService(),
            new NullLogger()
        );
        $service->incrementCounter('orders', 2.0);
        $service->sendMetrics();
        $this->assertNotNull($captured);
        $this->assertSame('api/metrics', $captured[0]);
        $this->assertSame(1, $captured[2]);
        $this->assertSame([['metric' => 'orders', 'variantValues' => ['enabled' => 2.0]]], $captured[1]['counters']);
        $this->assertNull($service->peekPayload());
    }

    public function testEmptyFlushDoesNotCallTransport(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('post');
        $features = $this->createMock(FeatureProviderInterface::class);
        $grpc = new class implements MetricsGrpcClient {
            public int $calls = 0;

            public function sendMetrics(array $payload, array $metadata = []): void
            {
                $this->calls++;
            }

            public function close(): void
            {
            }
        };

        $service = new MetricsService(
            new TogglySettings(['app_key' => 'app']),
            $http,
            $features,
            new MetricsRegistryService(),
            new NullLogger(),
            $grpc
        );
        $service->flush();
        $this->assertSame(0, $grpc->calls);
        $this->assertNull($service->peekPayload());
    }

    public function testVariantValuesPayloadAndGrpcSend(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('post');

        $featureProvider = $this->createMock(FeatureProviderInterface::class);
        $featureProvider->method('getFeaturesForMetric')->willReturn(null);

        $grpc = new class implements MetricsGrpcClient {
            /** @var array<string, mixed>|null */
            public $captured = null;

            public function sendMetrics(array $payload, array $metadata = []): void
            {
                $this->captured = $payload;
            }

            public function close(): void
            {
            }
        };

        $service = new MetricsService(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production', 'instance_name' => 'i1']),
            $http,
            $featureProvider,
            new MetricsRegistryService(),
            new NullLogger(),
            $grpc
        );

        $service->measure('revenue', 10.5);
        $service->incrementCounter('api-calls', 2);
        $service->observe('active-users', 100);

        $peek = $service->peekPayload();
        $this->assertNotNull($peek);
        $this->assertSame(['enabled' => 10.5], $peek['stats'][0]['variantValues']);
        $this->assertSame(['enabled' => 2.0], $peek['counters'][0]['variantValues']);
        $this->assertSame(['enabled' => 100.0], $peek['observations'][0]['variantValues']);
        $this->assertArrayHasKey('seconds', $peek['time']);

        $service->sendMetrics();
        $this->assertNotNull($grpc->captured);
        $this->assertSame('app', $grpc->captured['appKey']);
        $this->assertNull($service->peekPayload());
    }

    public function testSingleFlightSkipsOverlappingSend(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $featureProvider = $this->createMock(FeatureProviderInterface::class);
        $featureProvider->method('getFeaturesForMetric')->willReturn(null);

        $grpc = new class implements MetricsGrpcClient {
            public int $calls = 0;
            public ?MetricsService $service = null;

            public function sendMetrics(array $payload, array $metadata = []): void
            {
                $this->calls++;
                if ($this->service !== null) {
                    $this->service->sendMetrics();
                }
            }

            public function close(): void
            {
            }
        };

        $service = new MetricsService(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            $featureProvider,
            new MetricsRegistryService(),
            new NullLogger(),
            $grpc
        );
        $grpc->service = $service;
        $service->measure('m', 1);
        $service->sendMetrics();
        $this->assertSame(1, $grpc->calls);
    }

    public function testRegistryReentrancyDuringSendDoesNotDoubleClear(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $featureProvider = $this->createMock(FeatureProviderInterface::class);
        $featureProvider->method('getFeaturesForMetric')->willReturn(null);

        $grpc = new class implements MetricsGrpcClient {
            public int $calls = 0;
            /** @var array<string, mixed>|null */
            public $captured = null;

            public function sendMetrics(array $payload, array $metadata = []): void
            {
                $this->calls++;
                $this->captured = $payload;
            }

            public function close(): void
            {
            }
        };

        $registry = new MetricsRegistryService();
        $service = new MetricsService(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            $featureProvider,
            $registry,
            new NullLogger(),
            $grpc
        );

        $reentrantCalls = 0;
        $registry->registerMeasurements(function () use ($service, &$reentrantCalls): array {
            $reentrantCalls++;
            // Re-enter while outer sendMetrics is already in progress.
            $service->sendMetrics();
            return ['from-registry' => 3.0];
        });

        $service->measure('direct', 1.0);
        $service->sendMetrics();

        $this->assertSame(1, $reentrantCalls);
        $this->assertSame(1, $grpc->calls);
        $this->assertNotNull($grpc->captured);

        $metrics = array_column($grpc->captured['stats'], 'metric');
        $this->assertContains('direct', $metrics);
        $this->assertContains('from-registry', $metrics);
        $this->assertNull($service->peekPayload());
    }
}
