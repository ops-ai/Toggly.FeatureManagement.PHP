<?php

namespace Toggly\FeatureManagement\Contracts;

use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\JsonWebKeySet;

/**
 * Feature snapshot provider
 */
interface FeatureSnapshotProviderInterface
{
    /**
     * Save the snapshot of the features
     * @param FeatureDefinition[] $features
     * @param string|null $signature
     * @param string|null $keyId
     * @param int|null $timestamp
     * @param string|null $signedDefsJson Exact signed defs JSON from the server (for verify without re-serialize)
     * @param string|null $etag Definitions revision for conditional fetches
     */
    public function saveSnapshot(
        array $features,
        ?string $signature = null,
        ?string $keyId = null,
        ?int $timestamp = null,
        ?string $signedDefsJson = null,
        ?string $etag = null
    ): void;

    /**
     * Get the snapshot of the features
     * @return array{
     *   features: FeatureDefinition[]|null,
     *   signature: string|null,
     *   keyId: string|null,
     *   timestamp: int|null,
     *   signedDefsJson: string|null,
     *   etag: string|null
     * }
     */
    public function getFeaturesSnapshot(): array;

    /**
     * Save the snapshot of the JWKs
     */
    public function saveJwkSnapshot(JsonWebKeySet $jwks, int $timestamp): void;

    /**
     * Get the snapshot of the JWKs
     * @return array{jwks: JsonWebKeySet|null, timestamp: int|null}
     */
    public function getJwkSnapshot(): array;

    /**
     * Clear persisted feature and JWKS snapshots.
     */
    public function clear(): void;
}
