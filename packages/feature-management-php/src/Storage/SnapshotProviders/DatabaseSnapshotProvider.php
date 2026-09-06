<?php

namespace Toggly\FeatureManagement\Storage\SnapshotProviders;

use PDO;
use Toggly\FeatureManagement\Contracts\FeatureSnapshotProviderInterface;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\JsonWebKeySet;
use Toggly\FeatureManagement\Storage\SnapshotSettings;

/**
 * Database-based snapshot provider using PDO
 */
class DatabaseSnapshotProvider implements FeatureSnapshotProviderInterface
{
    private PDO $pdo;
    private SnapshotSettings $settings;
    private string $tableName;
    private string $jwkTableName;

    public function __construct(
        PDO $pdo,
        SnapshotSettings $settings,
        string $tableName = 'toggly_snapshots',
        string $jwkTableName = 'toggly_jwk_snapshots'
    ) {
        $this->pdo = $pdo;
        $this->settings = $settings;
        $this->tableName = $tableName;
        $this->jwkTableName = $jwkTableName;

        $this->ensureTablesExist();
    }

    /**
     * Ensure database tables exist
     */
    private function ensureTablesExist(): void
    {
        // Create snapshots table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (
            id VARCHAR(255) PRIMARY KEY,
            features TEXT NOT NULL,
            signature VARCHAR(255),
            key_id VARCHAR(255),
            timestamp BIGINT,
            signed_defs_json LONGTEXT,
            etag VARCHAR(255),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);

        // Best-effort migration for tables created before signed_defs_json/etag existed.
        try {
            $this->pdo->exec("ALTER TABLE {$this->tableName} ADD COLUMN signed_defs_json LONGTEXT");
        } catch (\Throwable $e) {
            // column may already exist
        }
        try {
            $this->pdo->exec("ALTER TABLE {$this->tableName} ADD COLUMN etag VARCHAR(255)");
        } catch (\Throwable $e) {
            // column may already exist
        }

        // Create JWK snapshots table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->jwkTableName} (
            id VARCHAR(255) PRIMARY KEY,
            jwks TEXT NOT NULL,
            timestamp BIGINT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
    }

    /**
     * @inheritDoc
     */
    public function saveSnapshot(
        array $features,
        ?string $signature = null,
        ?string $keyId = null,
        ?int $timestamp = null,
        ?string $signedDefsJson = null,
        ?string $etag = null
    ): void {
        $id = $this->settings->documentName ?? 'toggly_features';

        $featuresJson = json_encode(array_map(fn($f) => $f->toArray(), $features));

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->tableName} (id, features, signature, key_id, timestamp, signed_defs_json, etag, updated_at)
            VALUES (:id, :features, :signature, :key_id, :timestamp, :signed_defs_json, :etag, NOW())
            ON DUPLICATE KEY UPDATE
                features = VALUES(features),
                signature = VALUES(signature),
                key_id = VALUES(key_id),
                timestamp = VALUES(timestamp),
                signed_defs_json = VALUES(signed_defs_json),
                etag = VALUES(etag),
                updated_at = NOW()
        ");

        $stmt->execute([
            ':id' => $id,
            ':features' => $featuresJson,
            ':signature' => $signature,
            ':key_id' => $keyId,
            ':timestamp' => $timestamp,
            ':signed_defs_json' => $signedDefsJson,
            ':etag' => $etag,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getFeaturesSnapshot(): array
    {
        $id = $this->settings->documentName ?? 'toggly_features';

        $stmt = $this->pdo->prepare("SELECT features, signature, key_id, timestamp, signed_defs_json, etag FROM {$this->tableName} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'features' => null,
                'signature' => null,
                'keyId' => null,
                'timestamp' => null,
                'signedDefsJson' => null,
                'etag' => null,
            ];
        }

        $features = [];
        if (!empty($row['features'])) {
            $featuresData = json_decode($row['features'], true);
            if (is_array($featuresData)) {
                $features = array_map(function ($def) {
                    return new FeatureDefinition($def);
                }, $featuresData);
            }
        }

        return [
            'features' => $features,
            'signature' => $row['signature'] ?? null,
            'keyId' => $row['key_id'] ?? null,
            'timestamp' => $row['timestamp'] !== null ? (int)$row['timestamp'] : null,
            'signedDefsJson' => $row['signed_defs_json'] ?? null,
            'etag' => $row['etag'] ?? null,
        ];
    }

    /**
     * @inheritDoc
     */
    public function saveJwkSnapshot(JsonWebKeySet $jwks, int $timestamp): void
    {
        $id = $this->settings->jwkDocumentName ?? 'toggly_jwks';

        $jwksJson = json_encode([
            'keys' => array_map(function ($jwk) {
                return [
                    'kty' => $jwk->kty,
                    'use' => $jwk->use,
                    'kid' => $jwk->kid,
                    'crv' => $jwk->crv,
                    'x' => $jwk->x,
                    'y' => $jwk->y,
                    'alg' => $jwk->alg,
                ];
            }, $jwks->keys),
        ]);

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->jwkTableName} (id, jwks, timestamp, updated_at)
            VALUES (:id, :jwks, :timestamp, NOW())
            ON DUPLICATE KEY UPDATE
                jwks = VALUES(jwks),
                timestamp = VALUES(timestamp),
                updated_at = NOW()
        ");

        $stmt->execute([
            ':id' => $id,
            ':jwks' => $jwksJson,
            ':timestamp' => $timestamp,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getJwkSnapshot(): array
    {
        $id = $this->settings->jwkDocumentName ?? 'toggly_jwks';

        $stmt = $this->pdo->prepare("SELECT jwks, timestamp FROM {$this->jwkTableName} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'jwks' => null,
                'timestamp' => null,
            ];
        }

        $jwks = null;
        if (!empty($row['jwks'])) {
            $jwksData = json_decode($row['jwks'], true);
            if (is_array($jwksData)) {
                $jwks = new JsonWebKeySet($jwksData);
            }
        }

        return [
            'jwks' => $jwks,
            'timestamp' => $row['timestamp'] !== null ? (int)$row['timestamp'] : null,
        ];
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        $featuresId = $this->settings->documentName ?? 'toggly_features';
        $jwksId = $this->settings->jwkDocumentName ?? 'toggly_jwks';

        $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE id = :id");
        $stmt->execute([':id' => $featuresId]);

        $stmt = $this->pdo->prepare("DELETE FROM {$this->jwkTableName} WHERE id = :id");
        $stmt->execute([':id' => $jwksId]);
    }
}
