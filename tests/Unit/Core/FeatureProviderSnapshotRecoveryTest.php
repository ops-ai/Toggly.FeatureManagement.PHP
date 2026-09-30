<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureSnapshotProviderInterface;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Models\FeatureDefinition;

final class FeatureProviderSnapshotRecoveryTest extends TestCase
{
    public function testLoadsUnsignedSnapshotRestoresEtagAndKeepsFeatureAvailable(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('setLastETag')->with('"snapshot"');
        $snapshot = $this->createStub(FeatureSnapshotProviderInterface::class);
        $feature = new FeatureDefinition(['featureKey' => 'snapshot-flag', 'filters' => [['name' => 'AlwaysOn']]]);
        $snapshot->method('getFeaturesSnapshot')->willReturn(['features' => [$feature], 'signature' => null, 'keyId' => null, 'timestamp' => 123, 'signedDefsJson' => null, 'etag' => '"snapshot"']);

        $provider = $this->provider($http, $snapshot);

        $this->assertSame($feature, $provider->getFeatureDefinition('snapshot-flag'));
        $this->assertSame([$feature], $provider->getAllFeatureDefinitions());
    }

    public function testSnapshotFailureReportsErrorWithoutThrowingFromConstruction(): void
    {
        $http = $this->createStub(TogglyHttpClient::class);
        $snapshot = $this->createStub(FeatureSnapshotProviderInterface::class);
        $snapshot->method('getFeaturesSnapshot')->willThrowException(new \RuntimeException('offline cache'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Error loading from snapshot', $this->anything());

        $provider = $this->provider($http, $snapshot, $logger);

        $this->assertSame('Error loading from snapshot', $provider->getDebugInfo()['last_error']);
    }

    public function testEmptySnapshotLeavesProviderUnloadedWithoutInventingDefinitions(): void
    {
        $snapshot = $this->createStub(FeatureSnapshotProviderInterface::class);
        $snapshot->method('getFeaturesSnapshot')->willReturn(['features' => [], 'signature' => null, 'keyId' => null, 'timestamp' => null, 'signedDefsJson' => null, 'etag' => null]);
        $provider = $this->provider($this->createStub(TogglyHttpClient::class), $snapshot);

        $this->assertSame([], $provider->getAllFeatureDefinitions());
        $this->assertFalse($provider->getDebugInfo()['loaded']);
    }

    public function testRefreshFailureKeepsLastKnownGoodSnapshotDefinition(): void
    {
        $feature = new FeatureDefinition(['featureKey' => 'lkg-flag']);
        $snapshot = $this->createStub(FeatureSnapshotProviderInterface::class);
        $snapshot->method('getFeaturesSnapshot')->willReturn(['features' => [$feature], 'signature' => null, 'keyId' => null, 'timestamp' => null, 'signedDefsJson' => null, 'etag' => null]);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('getLastETag')->willReturn(null);
        $http->expects($this->once())->method('get')->willThrowException(new \RuntimeException('definitions unavailable'));
        $provider = $this->provider($http, $snapshot);

        $provider->refreshFeatures();

        $this->assertSame($feature, $provider->getFeatureDefinition('lkg-flag'));
        $this->assertSame('Error refreshing features list', $provider->getDebugInfo()['last_error']);
    }

    private function provider(TogglyHttpClient $http, FeatureSnapshotProviderInterface $snapshot, ?LoggerInterface $logger = null): FeatureProvider
    {
        return new FeatureProvider(new TogglySettings(['app_key' => 'app', 'environment' => 'Production', 'enable_live_updates' => false]), $http, $this->createStub(FeatureStateServiceInterface::class), $snapshot, $logger);
    }
}
