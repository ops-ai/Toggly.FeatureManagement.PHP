<?php

namespace Toggly\FeatureManagement\Tests\Unit\Storage\SnapshotProviders;

use MongoDB\Collection;
use MongoDB\DeleteResult;
use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\JsonWebKey;
use Toggly\FeatureManagement\Models\JsonWebKeySet;
use Toggly\FeatureManagement\Storage\SnapshotProviders\MongoDBSnapshotProvider;

final class MongoDBSnapshotProviderUnitTest extends TestCase
{
    public function testPersistsFeatureSnapshotWithSignatureAndEtagMetadata(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('replaceOne')->with(
            ['_id' => 'definitions-doc'],
            $this->callback(function (array $document): bool {
                $this->assertSame('definitions-doc', $document['_id']);
                $this->assertSame('signature', $document['signature']);
                $this->assertSame('key-1', $document['keyId']);
                $this->assertSame(17, $document['timestamp']);
                $this->assertSame('[{"featureKey":"checkout"}]', $document['signedDefsJson']);
                $this->assertSame('"revision"', $document['etag']);
                $this->assertSame(
                    [['featureKey' => 'checkout', 'filters' => [['name' => 'AlwaysOn']], 'metrics' => null, 'securedFeature' => false, 'requirementType' => 'Any']],
                    json_decode($document['data'], true, 512, JSON_THROW_ON_ERROR)
                );
                return $document['updatedAt'] instanceof \MongoDB\BSON\UTCDateTime;
            }),
            ['upsert' => true]
        );

        $this->provider($collection)->saveSnapshot(
            [new FeatureDefinition(['featureKey' => 'checkout', 'filters' => [['name' => 'AlwaysOn']]])],
            'signature',
            'key-1',
            17,
            '[{"featureKey":"checkout"}]',
            '"revision"'
        );
    }

    public function testEmptyAndCorruptFeatureDocumentsDoNotInventDefinitions(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->exactly(2))->method('findOne')->with(['_id' => 'definitions-doc'])
            ->willReturnOnConsecutiveCalls(null, ['data' => '{not-json', 'signature' => 'retained']);
        $provider = $this->provider($collection);

        $empty = $provider->getFeaturesSnapshot();
        $corrupt = $provider->getFeaturesSnapshot();

        $this->assertNull($empty['features']);
        $this->assertNull($empty['etag']);
        $this->assertSame([], $corrupt['features']);
        $this->assertSame('retained', $corrupt['signature']);
        $this->assertNull($corrupt['keyId']);
    }

    public function testPersistsAndReadsJwksWithOptionalFields(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('replaceOne')->with(
            ['_id' => 'jwks-doc'],
            $this->callback(function (array $document): bool {
                $key = json_decode($document['data'], true, 512, JSON_THROW_ON_ERROR)['keys'][0];
                $this->assertSame('k1', $key['kid']);
                $this->assertSame('EC', $key['kty']);
                $this->assertNull($key['crv']);
                $this->assertNull($key['x']);
                $this->assertNull($key['y']);
                $this->assertNull($key['alg']);
                return $document['updatedAt'] instanceof \MongoDB\BSON\UTCDateTime;
            }),
            ['upsert' => true]
        );
        $collection->expects($this->once())->method('findOne')->with(['_id' => 'jwks-doc'])->willReturn([
            'data' => '{"keys":[{"kid":"read-key","kty":"EC","use":"sig"}]}',
            'timestamp' => 23,
        ]);
        $provider = $this->provider($collection);

        $provider->saveJwkSnapshot(new JsonWebKeySet(['keys' => [new JsonWebKey(['kid' => 'k1', 'kty' => 'EC'])]]), 19);
        $snapshot = $provider->getJwkSnapshot();

        $this->assertSame(23, $snapshot['timestamp']);
        $this->assertSame('read-key', $snapshot['jwks']->keys[0]->kid);
        $this->assertSame('sig', $snapshot['jwks']->keys[0]->use);
    }

    public function testClearsAndReportsExistenceForEachSnapshotType(): void
    {
        $deleted = [];
        $deleteResult = $this->createStub(DeleteResult::class);
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->exactly(2))->method('deleteOne')->willReturnCallback(
            static function (array $filter) use (&$deleted, $deleteResult): DeleteResult {
                $deleted[] = $filter['_id'];
                return $deleteResult;
            }
        );
        $collection->expects($this->exactly(2))->method('countDocuments')->willReturnCallback(
            static function (array $filter): int {
                return $filter['_id'] === 'definitions-doc' ? 1 : 0;
            }
        );
        $provider = $this->provider($collection);

        $this->assertTrue($provider->hasFeaturesSnapshot());
        $this->assertFalse($provider->hasJwkSnapshot());
        $provider->clear();
        $this->assertSame(['definitions-doc', 'jwks-doc'], $deleted);
    }

    private function provider(Collection $collection): MongoDBSnapshotProvider
    {
        return new MongoDBSnapshotProvider($collection, 'unused', 'unused', 'definitions-doc', 'jwks-doc');
    }
}
