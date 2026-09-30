<?php

namespace Toggly\FeatureManagement\Tests\Storage\SnapshotProviders;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\JsonWebKey;
use Toggly\FeatureManagement\Models\JsonWebKeySet;
use Toggly\FeatureManagement\Storage\SnapshotProviders\DatabaseSnapshotProvider;
use Toggly\FeatureManagement\Storage\SnapshotSettings;

class DatabaseSnapshotProviderTest extends TestCase
{
    #[Test]
    public function it_persists_and_replaces_a_feature_snapshot_without_losing_signed_metadata(): void
    {
        $provider = $this->provider();

        $provider->saveSnapshot(
            [new FeatureDefinition(['featureKey' => 'checkout', 'filters' => []])],
            'old-signature',
            'old-key',
            1700000000,
            '{"defs":"old"}',
            '"old-etag"'
        );
        $provider->saveSnapshot(
            [new FeatureDefinition(['featureKey' => 'new-checkout', 'filters' => []])],
            'new-signature',
            'new-key',
            1700000001,
            '{"defs":"new"}',
            '"new-etag"'
        );

        $snapshot = $provider->getFeaturesSnapshot();

        $this->assertSame(['new-checkout'], array_map(fn (FeatureDefinition $feature) => $feature->featureKey, $snapshot['features']));
        $this->assertSame('new-signature', $snapshot['signature']);
        $this->assertSame('new-key', $snapshot['keyId']);
        $this->assertSame(1700000001, $snapshot['timestamp']);
        $this->assertSame('{"defs":"new"}', $snapshot['signedDefsJson']);
        $this->assertSame('"new-etag"', $snapshot['etag']);
    }

    #[Test]
    public function it_returns_an_empty_snapshot_contract_when_no_feature_snapshot_exists(): void
    {
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

    #[Test]
    public function it_recovers_from_an_empty_persisted_feature_payload(): void
    {
        $pdo = new SqliteCompatiblePdo();
        $provider = $this->provider($pdo);
        $pdo->prepare('INSERT INTO toggly_snapshots (id, features, signature, key_id, timestamp) VALUES (:id, :features, :signature, :key_id, :timestamp)')
            ->execute([
                ':id' => 'toggly_features',
                ':features' => '',
                ':signature' => 'signature',
                ':key_id' => 'key',
                ':timestamp' => 1700000002,
            ]);

        $snapshot = $provider->getFeaturesSnapshot();

        $this->assertSame([], $snapshot['features']);
        $this->assertSame('signature', $snapshot['signature']);
        $this->assertSame('key', $snapshot['keyId']);
        $this->assertSame(1700000002, $snapshot['timestamp']);
    }

    #[Test]
    public function it_persists_jwks_and_clear_removes_both_snapshot_types_using_configured_names(): void
    {
        $pdo = new SqliteCompatiblePdo();
        $provider = $this->provider($pdo, new SnapshotSettings([
            'document_name' => 'feature-snapshot',
            'jwk_document_name' => 'jwk-snapshot',
        ]));

        $provider->saveSnapshot([new FeatureDefinition(['featureKey' => 'search', 'filters' => []])]);
        $provider->saveJwkSnapshot(new JsonWebKeySet(['keys' => [new JsonWebKey([
            'kty' => 'EC',
            'use' => 'sig',
            'kid' => 'signing-key',
            'crv' => 'P-256',
            'x' => 'x-coordinate',
            'y' => 'y-coordinate',
            'alg' => 'ES256',
        ])]]), 1700000003);

        $storedJwks = $provider->getJwkSnapshot();
        $this->assertSame('signing-key', $storedJwks['jwks']->keys[0]->kid);
        $this->assertSame(1700000003, $storedJwks['timestamp']);

        $provider->clear();

        $this->assertNull($provider->getFeaturesSnapshot()['features']);
        $this->assertNull($provider->getJwkSnapshot()['jwks']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM toggly_snapshots')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM toggly_jwk_snapshots')->fetchColumn());
    }

    #[Test]
    public function it_returns_no_jwks_when_the_jwk_snapshot_is_missing_or_empty(): void
    {
        $pdo = new SqliteCompatiblePdo();
        $provider = $this->provider($pdo);

        $this->assertSame(['jwks' => null, 'timestamp' => null], $provider->getJwkSnapshot());

        $pdo->prepare('INSERT INTO toggly_jwk_snapshots (id, jwks, timestamp) VALUES (:id, :jwks, :timestamp)')
            ->execute([':id' => 'toggly_jwks', ':jwks' => '', ':timestamp' => 1700000004]);

        $this->assertSame(['jwks' => null, 'timestamp' => 1700000004], $provider->getJwkSnapshot());
    }

    private function provider(?PDO $pdo = null, ?SnapshotSettings $settings = null): DatabaseSnapshotProvider
    {
        return new DatabaseSnapshotProvider($pdo ?? new SqliteCompatiblePdo(), $settings ?? new SnapshotSettings());
    }
}

final class SqliteCompatiblePdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function exec(string $statement): int|false
    {
        $statement = str_replace(' ON UPDATE CURRENT_TIMESTAMP', '', $statement);

        if (str_starts_with($statement, 'ALTER TABLE')) {
            return 0;
        }

        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('NOW()', 'CURRENT_TIMESTAMP', $query);
        $query = preg_replace(
            '/ON DUPLICATE KEY UPDATE\s+features = VALUES\(features\),\s+signature = VALUES\(signature\),\s+key_id = VALUES\(key_id\),\s+timestamp = VALUES\(timestamp\),\s+signed_defs_json = VALUES\(signed_defs_json\),\s+etag = VALUES\(etag\),\s+updated_at = CURRENT_TIMESTAMP/s',
            'ON CONFLICT(id) DO UPDATE SET features = excluded.features, signature = excluded.signature, key_id = excluded.key_id, timestamp = excluded.timestamp, signed_defs_json = excluded.signed_defs_json, etag = excluded.etag, updated_at = CURRENT_TIMESTAMP',
            $query
        );
        $query = preg_replace(
            '/ON DUPLICATE KEY UPDATE\s+jwks = VALUES\(jwks\),\s+timestamp = VALUES\(timestamp\),\s+updated_at = CURRENT_TIMESTAMP/s',
            'ON CONFLICT(id) DO UPDATE SET jwks = excluded.jwks, timestamp = excluded.timestamp, updated_at = CURRENT_TIMESTAMP',
            $query
        );

        return parent::prepare($query, $options);
    }
}
