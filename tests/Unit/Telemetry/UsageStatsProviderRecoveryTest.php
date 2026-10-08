<?php

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureContextProviderInterface;
use Toggly\FeatureManagement\Core\UsageStatsProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Telemetry\IdentityHasher;
use Toggly\FeatureManagement\Telemetry\UsageGrpcClient;

class UsageStatsProviderRecoveryTest extends TestCase
{
    public function testFailedDeliveryPreservesPayloadForACompleteRetry(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $grpc = new class implements UsageGrpcClient {
            public int $attempts = 0;

            /** @var array<string, mixed>|null */
            public ?array $retriedPayload = null;

            public function sendStats(array $payload, array $metadata = []): void
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new \RuntimeException('network unavailable');
                }

                $this->retriedPayload = $payload;
            }

            public function close(): void
            {
            }
        };
        $context = $this->createMock(FeatureContextProviderInterface::class);
        $context->method('accessedInRequest')->willReturn(false);
        $context->method('getContextIdentifier')->willReturn('retry-user');

        $provider = new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            $context,
            new NullLogger(),
            $grpc
        );
        $provider->recordCheck('Checkout', true);
        $provider->recordUsage('Checkout');
        $provider->recordView('Checkout');
        $provider->recordDefinitionCacheHit();
        $provider->recordDefinitionCacheMiss();

        $provider->sendStats();
        $this->assertSame(1, $grpc->attempts);
        $this->assertNotNull($provider->peekPayload());

        $provider->sendStats();

        $this->assertSame(2, $grpc->attempts);
        $this->assertNotNull($grpc->retriedPayload);
        $this->assertSame(1, $grpc->retriedPayload['definitionCacheHits']);
        $this->assertSame(1, $grpc->retriedPayload['definitionCacheMisses']);
        $this->assertSame(1, $grpc->retriedPayload['totalUniqueUsers']);
        $this->assertSame(1, $grpc->retriedPayload['stats'][0]['variantStats']['enabled']['checkCount']);
        $this->assertSame(1, $grpc->retriedPayload['stats'][0]['variantStats']['enabled']['usedCount']);
        $this->assertSame(1, $grpc->retriedPayload['stats'][0]['variantStats']['enabled']['viewedCount']);
        $this->assertSame(1, $grpc->retriedPayload['stats'][0]['uniqueContextIdentifierEnabledCount']);
        $this->assertSame(1, $grpc->retriedPayload['stats'][0]['uniqueUsersUsedCount']);
        $this->assertContains(
            IdentityHasher::hashIdentity('retry-user'),
            $grpc->retriedPayload['stats'][0]['uniqueUserHashes']
        );
        $this->assertContains(
            IdentityHasher::hashIdentity('retry-user'),
            $grpc->retriedPayload['stats'][0]['uniqueViewedUserHashes']
        );
        $this->assertNull($provider->peekPayload());
    }

    public function testUniqueIdentifierLimitsKeepExistingHashesAndWarn(): void
    {
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            static function ($message, array $context = []) use (&$warnings): void {
                $warnings[] = [$message, $context];
            }
        );

        $http = $this->createMock(TogglyHttpClient::class);
        $grpc = new class implements UsageGrpcClient {
            public function sendStats(array $payload, array $metadata = []): void
            {
            }

            public function close(): void
            {
            }
        };
        $context = $this->createMock(FeatureContextProviderInterface::class);
        $context->method('getContextIdentifier')->willReturn('new-user');

        $provider = new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $http,
            $context,
            $logger,
            $grpc
        );
        $this->setPrivateProperty(
            $provider,
            'uniqueUserHashes',
            ['Checkout' => array_fill_keys(range(1, 10000), true)]
        );
        $this->setPrivateProperty(
            $provider,
            'uniqueViewedUserHashes',
            ['Checkout' => array_fill_keys(range(1, 10000), true)]
        );
        $this->setPrivateProperty(
            $provider,
            'applicationUniqueUserHashes',
            array_fill_keys(range(1, 10000), true)
        );

        $provider->recordUsage('Checkout');
        $provider->recordView('Checkout');

        $this->assertCount(10000, $this->privateProperty($provider, 'uniqueUserHashes')['Checkout']);
        $this->assertCount(10000, $this->privateProperty($provider, 'uniqueViewedUserHashes')['Checkout']);
        $this->assertCount(10000, $this->privateProperty($provider, 'applicationUniqueUserHashes'));
        $this->assertSame([
            ['Unique user hash limit reached for feature', ['feature' => 'Checkout']],
            ['Application-level unique user hash limit reached', []],
            ['Unique viewed user hash limit reached for feature', ['feature' => 'Checkout']],
            ['Application-level unique user hash limit reached', []],
        ], $warnings);
    }

    public function testMalformedRecoveryEntriesAreIgnoredWhileValidDataIsRestored(): void
    {
        $provider = new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production']),
            $this->createMock(TogglyHttpClient::class),
            null,
            new NullLogger(),
            new class implements UsageGrpcClient {
                public function sendStats(array $payload, array $metadata = []): void
                {
                }

                public function close(): void
                {
                }
            }
        );

        $restore = new \ReflectionMethod(UsageStatsProvider::class, 'restoreFromPayload');
        if (PHP_VERSION_ID < 80100) {
            $restore->setAccessible(true);
        }
        $restore->invoke($provider, [
            'stats' => [
                'not-a-stat',
                ['feature' => '', 'variantStats' => ['enabled' => ['checkCount' => 99]]],
                [
                    'feature' => 'Checkout',
                    'variantStats' => [
                        'corrupt' => 'not-a-variant',
                        'enabled' => [
                            'checkCount' => 2,
                            'requestCount' => 1,
                            'usedCount' => 3,
                            'viewedCount' => 4,
                        ],
                    ],
                    'uniqueUserHashes' => [101],
                    'uniqueViewedUserHashes' => [202],
                ],
            ],
            'uniqueUserHashes' => [303],
            'definitionCacheHits' => 5,
            'definitionCacheMisses' => 6,
        ]);

        $payload = $provider->peekPayload();
        $this->assertNotNull($payload);
        $this->assertCount(1, $payload['stats']);
        $this->assertSame('Checkout', $payload['stats'][0]['feature']);
        $this->assertSame([
            'checkCount' => 2,
            'requestCount' => 1,
            'usedCount' => 3,
            'viewedCount' => 4,
        ], $payload['stats'][0]['variantStats']['enabled']);
        $this->assertArrayNotHasKey('corrupt', $payload['stats'][0]['variantStats']);
        $this->assertSame([101], $payload['stats'][0]['uniqueUserHashes']);
        $this->assertSame([202], $payload['stats'][0]['uniqueViewedUserHashes']);
        $this->assertSame([303], $payload['uniqueUserHashes']);
        $this->assertSame(5, $payload['definitionCacheHits']);
        $this->assertSame(6, $payload['definitionCacheMisses']);
    }

    /** @param mixed $value */
    private function setPrivateProperty(object $object, string $property, $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $reflection->setValue($object, $value);
    }

    /** @return mixed */
    private function privateProperty(object $object, string $property)
    {
        $reflection = new \ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->getValue($object);
    }
}
