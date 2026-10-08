<?php

namespace Toggly\FeatureManagement\Tests\Unit\Storage;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\JsonWebKeySet;
use Toggly\FeatureManagement\Storage\SnapshotProviders\CacheSnapshotProvider;
use Toggly\FeatureManagement\Storage\SnapshotSettings;

final class CacheSnapshotProviderTest extends TestCase
{
    private CacheInterface&MockObject $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(CacheInterface::class);
    }

    public function testSavesAndLoadsFeatureSnapshotWithSignedRawDefinitions(): void
    {
        $stored = [];
        $this->cache->expects($this->once())
            ->method('set')
            ->with('custom-features', $this->isType('array'), 120)
            ->willReturnCallback(function (string $key, array $value, int $ttl) use (&$stored): bool {
                $stored[$key] = $value;

                return true;
            });
        $this->cache->expects($this->once())
            ->method('get')
            ->with('custom-features')
            ->willReturnCallback(function (string $key) use (&$stored): ?array {
                return $stored[$key] ?? null;
            });

        $provider = $this->provider(new SnapshotSettings([
            'document_name' => 'custom-features',
        ]), 120);
        $rawDefinitions = '[{"featureKey":"checkout","filters":[]}]';

        $provider->saveSnapshot([
            new FeatureDefinition([
                'featureKey' => 'checkout',
                'filters' => [],
                'metrics' => ['conversion'],
                'securedFeature' => true,
                'requirementType' => 'All',
            ]),
        ], 'signature', 'key-id', 1730000000, $rawDefinitions, '"revision-1"');

        $snapshot = $provider->getFeaturesSnapshot();

        $this->assertCount(1, $snapshot['features']);
        $this->assertInstanceOf(FeatureDefinition::class, $snapshot['features'][0]);
        $this->assertSame('checkout', $snapshot['features'][0]->featureKey);
        $this->assertSame(['conversion'], $snapshot['features'][0]->metrics);
        $this->assertTrue($snapshot['features'][0]->securedFeature);
        $this->assertSame('All', $snapshot['features'][0]->requirementType);
        $this->assertSame('signature', $snapshot['signature']);
        $this->assertSame('key-id', $snapshot['keyId']);
        $this->assertSame(1730000000, $snapshot['timestamp']);
        $this->assertSame($rawDefinitions, $snapshot['signedDefsJson']);
        $this->assertSame('"revision-1"', $snapshot['etag']);
    }

    public function testMissingFeatureSnapshotReturnsEmptyContractShape(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->with('toggly:features:snapshot')
            ->willReturn(null);

        $snapshot = $this->provider()->getFeaturesSnapshot();

        $this->assertSame([
            'features' => null,
            'signature' => null,
            'keyId' => null,
            'timestamp' => null,
            'signedDefsJson' => null,
            'etag' => null,
        ], $snapshot);
    }

    public function testMalformedFeatureSnapshotFallsBackToEmptyFeaturesAndMetadata(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn(['features' => 'corrupt']);

        $snapshot = $this->provider()->getFeaturesSnapshot();

        $this->assertSame([], $snapshot['features']);
        $this->assertNull($snapshot['signature']);
        $this->assertNull($snapshot['keyId']);
        $this->assertNull($snapshot['timestamp']);
        $this->assertNull($snapshot['signedDefsJson']);
        $this->assertNull($snapshot['etag']);
    }

    public function testCacheReadErrorsPropagateToTheCaller(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->willThrowException(new RuntimeException('cache unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cache unavailable');

        $this->provider()->getFeaturesSnapshot();
    }

    public function testSavesAndLoadsJwkSnapshot(): void
    {
        $stored = [];
        $this->cache->expects($this->once())
            ->method('set')
            ->with('custom-jwks', $this->isType('array'), 90)
            ->willReturnCallback(function (string $key, array $value, int $ttl) use (&$stored): bool {
                $stored[$key] = $value;

                return true;
            });
        $this->cache->expects($this->once())
            ->method('get')
            ->with('custom-jwks')
            ->willReturnCallback(function (string $key) use (&$stored): ?array {
                return $stored[$key] ?? null;
            });
        $provider = $this->provider(new SnapshotSettings([
            'jwk_document_name' => 'custom-jwks',
        ]), 90);

        $provider->saveJwkSnapshot(new JsonWebKeySet([
            'keys' => [[
                'kty' => 'EC',
                'use' => 'sig',
                'kid' => 'key-id',
                'crv' => 'P-256',
                'x' => 'x-coordinate',
                'y' => 'y-coordinate',
                'alg' => 'ES256',
            ]],
        ]), 1730000001);

        $snapshot = $provider->getJwkSnapshot();

        $this->assertInstanceOf(JsonWebKeySet::class, $snapshot['jwks']);
        $this->assertCount(1, $snapshot['jwks']->keys);
        $this->assertSame('key-id', $snapshot['jwks']->keys[0]->kid);
        $this->assertSame('ES256', $snapshot['jwks']->keys[0]->alg);
        $this->assertSame(1730000001, $snapshot['timestamp']);
    }

    public function testMissingOrCorruptJwkSnapshotReturnsEmptyContractShape(): void
    {
        $this->cache->expects($this->exactly(2))
            ->method('get')
            ->with('toggly:jwks:snapshot')
            ->willReturnOnConsecutiveCalls(null, false);
        $provider = $this->provider();

        $this->assertSame([
            'jwks' => null,
            'timestamp' => null,
        ], $provider->getJwkSnapshot());
        $this->assertSame([
            'jwks' => null,
            'timestamp' => null,
        ], $provider->getJwkSnapshot());
    }

    public function testClearDeletesBothConfiguredSnapshotKeys(): void
    {
        $deletedKeys = [];
        $this->cache->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function (string $key) use (&$deletedKeys): bool {
                $deletedKeys[] = $key;

                return true;
            });

        $this->provider(new SnapshotSettings([
            'document_name' => 'feature-key',
            'jwk_document_name' => 'jwk-key',
        ]))->clear();

        $this->assertSame(['feature-key', 'jwk-key'], $deletedKeys);
    }

    public function testCacheWriteErrorsPropagateToTheCaller(): void
    {
        $this->cache->expects($this->once())
            ->method('set')
            ->willThrowException(new RuntimeException('cache write unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cache write unavailable');

        $this->provider()->saveSnapshot([]);
    }

    public function testCacheDeleteErrorsPropagateToTheCaller(): void
    {
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('toggly:features:snapshot')
            ->willThrowException(new RuntimeException('cache delete unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cache delete unavailable');

        $this->provider()->clear();
    }

    private function provider(?SnapshotSettings $settings = null, int $ttl = 86400): CacheSnapshotProvider
    {
        return new CacheSnapshotProvider($this->cache, $settings ?? new SnapshotSettings(), $ttl);
    }
}
