<?php

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Telemetry\GrpcPayloadConverter;
use Toggly\FeatureManagement\Telemetry\Pb\Metrics\MetricStat;
use Toggly\FeatureManagement\Telemetry\Pb\Usage\FeatureStat;

final class GrpcPayloadWireContractTest extends TestCase
{
    public function testMetricsPayloadRoundTripsTypedValuesAndToleratesUnknownFutureFields(): void
    {
        $message = GrpcPayloadConverter::metricStatFromPayload([
            'appKey' => 'shop',
            'environment' => 'Production',
            'time' => ['seconds' => 1700000100, 'nanos' => 42],
            'instanceName' => 'metrics-worker-7',
            'stats' => [
                ['metric' => 'checkout_complete', 'feature' => 'checkout', 'variantValues' => ['control' => '2.5', 'treatment' => 3]],
                'discarded-stat',
            ],
            'counters' => [
                ['metric' => 'api_calls', 'feature' => 'api-rate-limit', 'variantValues' => ['enabled' => '4']],
                null,
            ],
            'observations' => [
                [
                    'metric' => 'response_ms',
                    'feature' => 'checkout',
                    'time' => ['seconds' => 1700000099, 'nanos' => 900],
                    'variantValues' => ['treatment' => '12.75'],
                ],
                false,
            ],
        ]);

        $roundTrip = new MetricStat();
        $roundTrip->mergeFromString($message->serializeToString() . $this->unknownVarintField());

        $this->assertSame('shop', $roundTrip->getAppKey());
        $this->assertSame('Production', $roundTrip->getEnvironment());
        $this->assertTrue($roundTrip->hasTime());
        $this->assertSame(1700000100, $roundTrip->getTime()->getSeconds());
        $this->assertSame(42, $roundTrip->getTime()->getNanos());
        $this->assertTrue($roundTrip->hasInstanceName());
        $this->assertSame('metrics-worker-7', $roundTrip->getInstanceName());

        $this->assertCount(1, $roundTrip->getStats());
        $this->assertCount(1, $roundTrip->getCounters());
        $this->assertCount(1, $roundTrip->getObservations());

        $stat = $roundTrip->getStats()[0];
        $this->assertSame('checkout_complete', $stat->getMetric());
        $this->assertTrue($stat->hasFeature());
        $this->assertSame('checkout', $stat->getFeature());
        $this->assertSame(['control' => 2.5, 'treatment' => 3.0], iterator_to_array($stat->getVariantValues()));

        $counter = $roundTrip->getCounters()[0];
        $this->assertSame('api_calls', $counter->getMetric());
        $this->assertTrue($counter->hasFeature());
        $this->assertSame('api-rate-limit', $counter->getFeature());
        $this->assertSame(['enabled' => 4.0], iterator_to_array($counter->getVariantValues()));

        $observation = $roundTrip->getObservations()[0];
        $this->assertSame('response_ms', $observation->getMetric());
        $this->assertTrue($observation->hasFeature());
        $this->assertSame('checkout', $observation->getFeature());
        $this->assertTrue($observation->hasTime());
        $this->assertSame(1700000099, $observation->getTime()->getSeconds());
        $this->assertSame(900, $observation->getTime()->getNanos());
        $this->assertSame(['treatment' => 12.75], iterator_to_array($observation->getVariantValues()));
    }

    public function testUsagePayloadRoundTripsPresenceCountersAndValidNestedStatsOnly(): void
    {
        $message = GrpcPayloadConverter::featureStatFromPayload([
            'appKey' => 'shop',
            'environment' => 'Production',
            'time' => ['seconds' => 1700000200, 'nanos' => 11],
            'processStartTime' => ['seconds' => 1700000000, 'nanos' => 0],
            'instanceName' => 'usage-worker-2',
            'appVersion' => '2.3.4',
            'totalUniqueUsers' => '12',
            'uniqueUserHashes' => ['101', 202],
            'definitionCacheHits' => 0,
            'definitionCacheMisses' => '3',
            'stats' => [
                [
                    'feature' => 'checkout',
                    'uniqueContextIdentifierEnabledCount' => '7',
                    'uniqueContextIdentifierDisabledCount' => 2,
                    'uniqueUsersUsedCount' => '5',
                    'uniqueUserHashes' => ['303', 404],
                    'uniqueViewedUserHashes' => ['505'],
                    'variantStats' => [
                        'control' => ['checkCount' => '9', 'requestCount' => 8, 'usedCount' => '7', 'viewedCount' => 6],
                        'discarded' => 'not-a-stat',
                    ],
                ],
                'discarded-stat',
            ],
        ]);

        $roundTrip = new FeatureStat();
        $roundTrip->mergeFromString($message->serializeToString() . $this->unknownVarintField());

        $this->assertSame('shop', $roundTrip->getAppKey());
        $this->assertSame('Production', $roundTrip->getEnvironment());
        $this->assertSame(12, $roundTrip->getTotalUniqueUsers());
        $this->assertSame([101, 202], iterator_to_array($roundTrip->getUniqueUserHashes()));
        $this->assertTrue($roundTrip->hasTime());
        $this->assertSame(1700000200, $roundTrip->getTime()->getSeconds());
        $this->assertTrue($roundTrip->hasProcessStartTime());
        $this->assertSame(1700000000, $roundTrip->getProcessStartTime()->getSeconds());
        $this->assertTrue($roundTrip->hasInstanceName());
        $this->assertSame('usage-worker-2', $roundTrip->getInstanceName());
        $this->assertTrue($roundTrip->hasAppVersion());
        $this->assertSame('2.3.4', $roundTrip->getAppVersion());
        $this->assertTrue($roundTrip->hasDefinitionCacheHits());
        $this->assertSame(0, $roundTrip->getDefinitionCacheHits());
        $this->assertTrue($roundTrip->hasDefinitionCacheMisses());
        $this->assertSame(3, $roundTrip->getDefinitionCacheMisses());

        $this->assertCount(1, $roundTrip->getStats());
        $stat = $roundTrip->getStats()[0];
        $this->assertSame('checkout', $stat->getFeature());
        $this->assertSame(7, $stat->getUniqueContextIdentifierEnabledCount());
        $this->assertSame(2, $stat->getUniqueContextIdentifierDisabledCount());
        $this->assertSame(5, $stat->getUniqueUsersUsedCount());
        $this->assertSame([303, 404], iterator_to_array($stat->getUniqueUserHashes()));
        $this->assertSame([505], iterator_to_array($stat->getUniqueViewedUserHashes()));
        $this->assertCount(1, $stat->getVariantStats());
        $control = $stat->getVariantStats()['control'];
        $this->assertSame(9, $control->getCheckCount());
        $this->assertSame(8, $control->getRequestCount());
        $this->assertSame(7, $control->getUsedCount());
        $this->assertSame(6, $control->getViewedCount());
    }

    private function unknownVarintField(): string
    {
        // Field number 100, varint value 1: newer servers may add such fields.
        return "\xA0\x06\x01";
    }
}
