<?php

namespace Toggly\FeatureManagement\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\JsonWebKeySet;
use Toggly\FeatureManagement\Security\EcdsaSignatureVerifier;
use Toggly\FeatureManagement\Security\JwkManager;
use Toggly\FeatureManagement\Storage\SnapshotProviders\FileSnapshotProvider;
use Toggly\FeatureManagement\Storage\SnapshotSettings;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\NullLogger;

/**
 * Golden regression: sign → save → load → verify raw defs (no re-serialize).
 */
class SignedSnapshotRawDefsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/toggly-snapshot-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function testSignSaveLoadVerifyRawDefsRoundTrip(): void
    {
        $keyPair = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $this->assertNotFalse($keyPair);

        $details = openssl_pkey_get_details($keyPair);
        $this->assertIsArray($details);
        $x = $this->pad32($details['ec']['x']);
        $y = $this->pad32($details['ec']['y']);
        $kid = strtoupper(bin2hex(sha1($x . $y, true))) . 'ES256';

        $rawDefs = '[{"featureKey":"demo","filters":[{"name":"AlwaysOn"}],"metrics":[],"securedFeature":false,"requirementType":"Any"}]';
        $timestamp = 1730000000;
        $dataToVerify = $rawDefs . '|' . $timestamp;
        $firstHash = hash('sha256', $dataToVerify, true);

        $signatureDer = '';
        $ok = openssl_sign($firstHash, $signatureDer, $keyPair, OPENSSL_ALGO_SHA256);
        $this->assertTrue($ok);
        $signatureB64 = base64_encode($signatureDer);

        $features = [
            new FeatureDefinition([
                'featureKey' => 'demo',
                'filters' => [['name' => 'AlwaysOn']],
            ]),
        ];

        $settings = new SnapshotSettings([
            'document_name' => 'features.json',
            'jwk_document_name' => 'jwks.json',
        ]);
        $provider = new FileSnapshotProvider($this->tempDir, $settings);

        $provider->saveSnapshot(
            $features,
            $signatureB64,
            $kid,
            $timestamp,
            $rawDefs,
            '"rev-1"'
        );

        $jwks = new JsonWebKeySet([
            'keys' => [[
                'kty' => 'EC',
                'use' => 'sig',
                'kid' => $kid,
                'crv' => 'P-256',
                'x' => $this->base64UrlEncode($x),
                'y' => $this->base64UrlEncode($y),
                'alg' => 'ES256',
            ]],
        ]);
        $provider->saveJwkSnapshot($jwks, time() + 86400);

        $loaded = $provider->getFeaturesSnapshot();
        $this->assertNotNull($loaded['features']);
        $this->assertSame($rawDefs, $loaded['signedDefsJson']);
        $this->assertSame($signatureB64, $loaded['signature']);
        $this->assertSame($kid, $loaded['keyId']);
        $this->assertSame($timestamp, $loaded['timestamp']);
        $this->assertSame('"rev-1"', $loaded['etag']);

        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $togglyHttp = new TogglyHttpClient($httpClient, $requestFactory, 'https://example.test/');
        $jwkManager = new JwkManager($togglyHttp, 'https://example.test/', $provider, null, new NullLogger());
        $verifier = new EcdsaSignatureVerifier($jwkManager, new NullLogger());

        $valid = $verifier->verifySnapshot(
            $loaded['signedDefsJson'],
            $loaded['signature'],
            $loaded['keyId'],
            $loaded['timestamp']
        );
        $this->assertTrue($valid, 'raw signedDefsJson must verify after storage round-trip');
    }

    public function testFileProviderClearRemovesSnapshots(): void
    {
        $provider = new FileSnapshotProvider(
            $this->tempDir,
            new SnapshotSettings(['document_name' => 'features.json', 'jwk_document_name' => 'jwks.json'])
        );

        $provider->saveSnapshot([
            new FeatureDefinition(['featureKey' => 'f1', 'filters' => []]),
        ], 'sig', 'kid', 1, '[]', 'e1');

        $provider->saveJwkSnapshot(new JsonWebKeySet([
            'keys' => [[
                'kty' => 'EC',
                'use' => 'sig',
                'kid' => 'kid',
                'crv' => 'P-256',
                'x' => $this->base64UrlEncode(str_repeat("\0", 32)),
                'y' => $this->base64UrlEncode(str_repeat("\1", 32)),
                'alg' => 'ES256',
            ]],
        ]), time() + 3600);

        $this->assertFileExists($this->tempDir . '/features.json');
        $this->assertFileExists($this->tempDir . '/jwks.json');

        $provider->clear();

        $this->assertFileDoesNotExist($this->tempDir . '/features.json');
        $this->assertFileDoesNotExist($this->tempDir . '/jwks.json');
        $empty = $provider->getFeaturesSnapshot();
        $this->assertNull($empty['features']);
        $this->assertNull($empty['signedDefsJson']);
    }

    private function pad32(string $bin): string
    {
        if (strlen($bin) >= 32) {
            return substr($bin, -32);
        }
        return str_pad($bin, 32, "\x00", STR_PAD_LEFT);
    }

    private function base64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
