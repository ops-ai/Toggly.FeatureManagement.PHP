<?php

namespace Toggly\FeatureManagement\Storage\SnapshotProviders;

use Toggly\FeatureManagement\Contracts\FeatureSnapshotProviderInterface;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\JsonWebKeySet;
use Toggly\FeatureManagement\Storage\SnapshotSettings;

/**
 * File-based snapshot provider
 */
class FileSnapshotProvider implements FeatureSnapshotProviderInterface
{
    private string $directory;
    private SnapshotSettings $settings;

    public function __construct(
        string $directory,
        SnapshotSettings $settings
    ) {
        $this->directory = rtrim($directory, '/') . '/';
        $this->settings = $settings;

        // Ensure directory exists
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
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
        $filename = $this->settings->documentName ?? 'toggly_features.json';
        $filepath = $this->directory . $filename;

        $data = [
            'features' => array_map(fn($f) => $f->toArray(), $features),
            'signature' => $signature,
            'keyId' => $keyId,
            'timestamp' => $timestamp,
            'signedDefsJson' => $signedDefsJson,
            'etag' => $etag,
        ];

        // Atomic write using temp file
        $tempFile = $filepath . '.tmp';
        file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tempFile, $filepath);
    }

    /**
     * @inheritDoc
     */
    public function getFeaturesSnapshot(): array
    {
        $filename = $this->settings->documentName ?? 'toggly_features.json';
        $filepath = $this->directory . $filename;

        $empty = [
            'features' => null,
            'signature' => null,
            'keyId' => null,
            'timestamp' => null,
            'signedDefsJson' => null,
            'etag' => null,
        ];

        if (!file_exists($filepath)) {
            return $empty;
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            return $empty;
        }

        $data = json_decode($content, true);
        if ($data === null) {
            return $empty;
        }

        $features = [];
        if (isset($data['features']) && is_array($data['features'])) {
            $features = array_map(function ($def) {
                return new FeatureDefinition($def);
            }, $data['features']);
        }

        return [
            'features' => $features,
            'signature' => $data['signature'] ?? null,
            'keyId' => $data['keyId'] ?? null,
            'timestamp' => $data['timestamp'] ?? null,
            'signedDefsJson' => $data['signedDefsJson'] ?? null,
            'etag' => $data['etag'] ?? null,
        ];
    }

    /**
     * @inheritDoc
     */
    public function saveJwkSnapshot(JsonWebKeySet $jwks, int $timestamp): void
    {
        $filename = $this->settings->jwkDocumentName ?? 'toggly_jwks.json';
        $filepath = $this->directory . $filename;

        $data = [
            'jwks' => [
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
            ],
            'timestamp' => $timestamp,
        ];

        // Atomic write using temp file
        $tempFile = $filepath . '.tmp';
        file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tempFile, $filepath);
    }

    /**
     * @inheritDoc
     */
    public function getJwkSnapshot(): array
    {
        $filename = $this->settings->jwkDocumentName ?? 'toggly_jwks.json';
        $filepath = $this->directory . $filename;

        if (!file_exists($filepath)) {
            return [
                'jwks' => null,
                'timestamp' => null,
            ];
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            return [
                'jwks' => null,
                'timestamp' => null,
            ];
        }

        $data = json_decode($content, true);
        if ($data === null) {
            return [
                'jwks' => null,
                'timestamp' => null,
            ];
        }

        $jwks = null;
        if (isset($data['jwks'])) {
            $jwks = new JsonWebKeySet($data['jwks']);
        }

        return [
            'jwks' => $jwks,
            'timestamp' => $data['timestamp'] ?? null,
        ];
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        $featuresFile = $this->directory . ($this->settings->documentName ?? 'toggly_features.json');
        $jwksFile = $this->directory . ($this->settings->jwkDocumentName ?? 'toggly_jwks.json');
        if (file_exists($featuresFile)) {
            @unlink($featuresFile);
        }
        if (file_exists($jwksFile)) {
            @unlink($jwksFile);
        }
    }
}
