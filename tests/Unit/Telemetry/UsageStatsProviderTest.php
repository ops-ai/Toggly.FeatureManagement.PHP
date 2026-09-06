<?php

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureContextProviderInterface;
use Toggly\FeatureManagement\Core\UsageStatsProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Telemetry\GrpcClients;
use Toggly\FeatureManagement\Telemetry\IdentityHasher;
use Toggly\FeatureManagement\Telemetry\UsageGrpcClient;

class UsageStatsProviderTest extends TestCase
{
    public function testVariantStatsAndRecordViewPayload(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('post');

        $grpc = new class implements UsageGrpcClient {
            /** @var array<string, mixed>|null */
            public $captured = null;

            public function sendStats(array $payload, array $metadata = []): void
            {
                $this->captured = $payload;
            }

            public function close(): void
            {
            }
        };

        $context = $this->createMock(FeatureContextProviderInterface::class);
        $context->method('accessedInRequest')->willReturn(false);
        $context->method('getContextIdentifier')->willReturn('user-1');

        $provider = new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production', 'instance_name' => 'i1']),
            $http,
            $context,
            new NullLogger(),
            $grpc
        );

        $provider->recordCheck('FeatureA', true);
        $provider->recordCheck('FeatureA', false);
        $provider->recordUsage('FeatureA');
        $provider->recordView('FeatureA');

        $peek = $provider->peekPayload();
        $this->assertNotNull($peek);
        $this->assertArrayHasKey('variantStats', $peek['stats'][0]);
        $this->assertSame(1, $peek['stats'][0]['variantStats']['enabled']['checkCount']);
        $this->assertSame(1, $peek['stats'][0]['variantStats']['enabled']['requestCount']);
        $this->assertSame(1, $peek['stats'][0]['variantStats']['enabled']['usedCount']);
        $this->assertSame(1, $peek['stats'][0]['variantStats']['enabled']['viewedCount']);
        $this->assertSame(1, $peek['stats'][0]['variantStats']['disabled']['checkCount']);
        $this->assertArrayNotHasKey('enabledCount', $peek['stats'][0]);
        $this->assertContains(IdentityHasher::hashIdentity('user-1'), $peek['stats'][0]['uniqueUserHashes']);
        $this->assertContains(IdentityHasher::hashIdentity('user-1'), $peek['stats'][0]['uniqueViewedUserHashes']);
        $this->assertContains(IdentityHasher::hashIdentity('user-1'), $peek['uniqueUserHashes']);

        $provider->sendStats();
        $this->assertNotNull($grpc->captured);
        $this->assertSame('app', $grpc->captured['appKey']);
        $this->assertNull($provider->peekPayload());
    }

    public function testSingleFlightSkipsOverlappingSend(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $grpc = new class implements UsageGrpcClient {
            public int $calls = 0;
            public ?UsageStatsProvider $provider = null;

            public function sendStats(array $payload, array $metadata = []): void
            {
                $this->calls++;
                // Re-entrant send while in progress should no-op
                if ($this->provider !== null) {
                    $this->provider->sendStats();
                }
            }

            public function close(): void
            {
            }
        };

        $provider = new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            null,
            new NullLogger(),
            $grpc
        );
        $grpc->provider = $provider;
        $provider->recordCheck('F', true);
        $provider->sendStats();
        $this->assertSame(1, $grpc->calls);
    }

    public function testHttpFallbackWhenNoGrpcClient(): void
    {
        if (GrpcClients::isAvailable()) {
            $this->markTestSkipped('gRPC available in this environment; HTTP fallback path not exercised');
        }

        $posted = null;
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())
            ->method('post')
            ->with(
                'api/usage/stats',
                $this->callback(function ($payload) use (&$posted) {
                    $posted = $payload;
                    return isset($payload['stats'][0]['variantStats']);
                })
            );

        $provider = new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            null,
            new NullLogger(),
            null
        );
        // Force HTTP by injecting null grpc — constructor may still try create();
        // when unavailable, grpcClient stays null.
        $provider->recordCheck('FeatureA', true);
        $provider->sendStats();
        $this->assertIsArray($posted);
        $this->assertIsString($posted['time']);
    }

    public function testGrpcTarget(): void
    {
        $this->assertSame('app.toggly.io:443', GrpcClients::grpcTarget('https://app.toggly.io/'));
        $this->assertSame('example.com:8443', GrpcClients::grpcTarget('https://example.com:8443/path'));
    }

    public function testFailedSendRestoresUniqueUsageMaps(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $grpc = new class implements UsageGrpcClient {
            public function sendStats(array $payload, array $metadata = []): void
            {
                throw new \RuntimeException('send failed');
            }

            public function close(): void
            {
            }
        };

        $userId = 'user-restore-1';
        $context = $this->createMock(FeatureContextProviderInterface::class);
        $context->method('accessedInRequest')->willReturn(false);
        $context->method('getContextIdentifier')->willReturn($userId);

        $provider = new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            $context,
            new NullLogger(),
            $grpc
        );

        $provider->recordCheck('FeatureA', true);
        $provider->recordCheck('FeatureA', false);
        $provider->recordUsage('FeatureA');

        $before = $provider->peekPayload();
        $this->assertNotNull($before);
        $this->assertSame(1, $before['stats'][0]['uniqueContextIdentifierEnabledCount']);
        $this->assertSame(1, $before['stats'][0]['uniqueContextIdentifierDisabledCount']);
        $this->assertSame(1, $before['stats'][0]['uniqueUsersUsedCount']);

        $provider->sendStats();

        $afterFailure = $provider->peekPayload();
        $this->assertNotNull($afterFailure);
        $this->assertSame(1, $afterFailure['stats'][0]['uniqueContextIdentifierEnabledCount']);
        $this->assertSame(1, $afterFailure['stats'][0]['uniqueContextIdentifierDisabledCount']);
        $this->assertSame(1, $afterFailure['stats'][0]['uniqueUsersUsedCount']);

        // Same identity must remain a single unique entry (hash sets restored, not counts alone).
        $provider->recordCheck('FeatureA', true);
        $afterRerecord = $provider->peekPayload();
        $this->assertNotNull($afterRerecord);
        $this->assertSame(1, $afterRerecord['stats'][0]['uniqueContextIdentifierEnabledCount']);
        $this->assertSame(2, $afterRerecord['stats'][0]['variantStats']['enabled']['checkCount']);
    }
}
