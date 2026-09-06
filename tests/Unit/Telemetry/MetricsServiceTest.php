<?php

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Core\MetricsRegistryService;
use Toggly\FeatureManagement\Core\MetricsService;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Telemetry\MetricsGrpcClient;

class MetricsServiceTest extends TestCase
{
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
