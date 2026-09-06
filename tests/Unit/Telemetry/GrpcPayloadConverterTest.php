<?php

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\SdkIdentity;
use Toggly\FeatureManagement\Telemetry\GrpcClients;
use Toggly\FeatureManagement\Telemetry\GrpcPayloadConverter;
use Toggly\FeatureManagement\Telemetry\IdentityHasher;
use Toggly\FeatureManagement\Telemetry\NativeMetricsGrpcClient;
use Toggly\FeatureManagement\Telemetry\NativeUsageGrpcClient;

class GrpcPayloadConverterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Google\Protobuf\Internal\Message::class)) {
            $this->markTestSkipped('google/protobuf required for payload converter tests');
        }
    }

    public function testFeatureStatFromPayloadSerializesWithTimestampsAndVariantStats(): void
    {
        $payload = [
            'appKey' => 'app',
            'environment' => 'Production',
            'time' => ['seconds' => 1700000000, 'nanos' => 0],
            'processStartTime' => ['seconds' => 1699990000, 'nanos' => 500],
            'instanceName' => 'i1',
            'appVersion' => '1.0.0',
            'totalUniqueUsers' => 1,
            'uniqueUserHashes' => [IdentityHasher::hashIdentity('user-1')],
            'stats' => [
                [
                    'feature' => 'FeatureA',
                    'uniqueContextIdentifierEnabledCount' => 1,
                    'uniqueContextIdentifierDisabledCount' => 0,
                    'uniqueUsersUsedCount' => 1,
                    'uniqueUserHashes' => [IdentityHasher::hashIdentity('user-1')],
                    'uniqueViewedUserHashes' => [IdentityHasher::hashIdentity('user-1')],
                    'variantStats' => [
                        'enabled' => [
                            'checkCount' => 2,
                            'requestCount' => 1,
                            'usedCount' => 1,
                            'viewedCount' => 1,
                        ],
                        'disabled' => [
                            'checkCount' => 1,
                            'requestCount' => 0,
                            'usedCount' => 0,
                            'viewedCount' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $msg = GrpcPayloadConverter::featureStatFromPayload($payload);

        $this->assertNotNull($msg->getTime());
        $this->assertSame(1700000000, $msg->getTime()->getSeconds());
        $this->assertNotNull($msg->getProcessStartTime());
        $this->assertSame(1699990000, $msg->getProcessStartTime()->getSeconds());
        $this->assertSame(500, $msg->getProcessStartTime()->getNanos());

        $stat = $msg->getStats()[0];
        $this->assertSame('FeatureA', $stat->getFeature());
        $this->assertArrayHasKey('enabled', iterator_to_array($stat->getVariantStats()));
        $enabled = $stat->getVariantStats()['enabled'];
        $this->assertSame(2, $enabled->getCheckCount());
        $this->assertSame(1, $enabled->getViewedCount());
        $this->assertContains(IdentityHasher::hashIdentity('user-1'), iterator_to_array($stat->getUniqueViewedUserHashes()));

        $bytes = $msg->serializeToString();
        $this->assertNotSame('', $bytes);
        $this->assertGreaterThan(0, strlen($bytes));
    }

    public function testMetricStatFromPayloadSerializesObservationsAndVariantValues(): void
    {
        $payload = [
            'appKey' => 'app',
            'environment' => 'Production',
            'time' => ['seconds' => 1700000100, 'nanos' => 0],
            'instanceName' => 'i1',
            'stats' => [
                [
                    'metric' => 'revenue',
                    'feature' => 'FeatureA',
                    'variantValues' => ['enabled' => 12.5],
                ],
            ],
            'counters' => [
                [
                    'metric' => 'api-calls',
                    'variantValues' => ['enabled' => 3.0],
                ],
            ],
            'observations' => [
                [
                    'metric' => 'active-users',
                    'time' => ['seconds' => 1700000050, 'nanos' => 250],
                    'variantValues' => ['enabled' => 100.0],
                ],
            ],
        ];

        $msg = GrpcPayloadConverter::metricStatFromPayload($payload);

        $this->assertNotNull($msg->getTime());
        $this->assertSame(1700000100, $msg->getTime()->getSeconds());

        $this->assertSame('revenue', $msg->getStats()[0]->getMetric());
        $this->assertSame(12.5, $msg->getStats()[0]->getVariantValues()['enabled']);
        $this->assertSame(3.0, $msg->getCounters()[0]->getVariantValues()['enabled']);

        $obs = $msg->getObservations()[0];
        $this->assertNotNull($obs->getTime());
        $this->assertSame(1700000050, $obs->getTime()->getSeconds());
        $this->assertSame(250, $obs->getTime()->getNanos());
        $this->assertSame(100.0, $obs->getVariantValues()['enabled']);

        $bytes = $msg->serializeToString();
        $this->assertNotSame('', $bytes);
        $this->assertGreaterThan(0, strlen($bytes));
    }

    public function testNativeClientsAttachUaMetadataOnSend(): void
    {
        $ua = SdkIdentity::userAgent();
        $defaultMeta = [GrpcClients::GRPC_USER_AGENT_METADATA_KEY => [$ua]];

        $usageStub = new class {
            /** @var array<string, string[]>|null */
            public $lastMetadata = null;

            public function SendStats($argument, array $metadata = [], array $options = [])
            {
                $this->lastMetadata = $metadata;

                return new class {
                    public function wait(): array
                    {
                        return [null, (object) ['code' => 0, 'details' => '']];
                    }
                };
            }
        };

        $usageClient = new NativeUsageGrpcClient($usageStub, $defaultMeta);
        $usageClient->sendStats([
            'appKey' => 'app',
            'environment' => 'Production',
            'time' => ['seconds' => 1, 'nanos' => 0],
            'stats' => [],
            'uniqueUserHashes' => [],
            'totalUniqueUsers' => 0,
        ]);

        $this->assertIsArray($usageStub->lastMetadata);
        $this->assertArrayHasKey('ua', $usageStub->lastMetadata);
        $this->assertSame([$ua], $usageStub->lastMetadata['ua']);
        $this->assertStringStartsWith('toggly-php/', $usageStub->lastMetadata['ua'][0]);

        $metricsStub = new class {
            /** @var array<string, string[]>|null */
            public $lastMetadata = null;

            public function SendMetrics($argument, array $metadata = [], array $options = [])
            {
                $this->lastMetadata = $metadata;

                return new class {
                    public function wait(): array
                    {
                        return [null, (object) ['code' => 0, 'details' => '']];
                    }
                };
            }
        };

        $metricsClient = new NativeMetricsGrpcClient($metricsStub, $defaultMeta);
        $metricsClient->sendMetrics([
            'appKey' => 'app',
            'environment' => 'Production',
            'time' => ['seconds' => 1, 'nanos' => 0],
            'stats' => [],
            'counters' => [],
            'observations' => [],
        ]);

        $this->assertIsArray($metricsStub->lastMetadata);
        $this->assertArrayHasKey('ua', $metricsStub->lastMetadata);
        $this->assertSame([$ua], $metricsStub->lastMetadata['ua']);
    }
}
