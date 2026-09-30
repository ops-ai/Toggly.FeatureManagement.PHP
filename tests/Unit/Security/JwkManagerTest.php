<?php

namespace Toggly\FeatureManagement\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Models\JsonWebKeySet;
use Toggly\FeatureManagement\Security\JwkManager;
use Toggly\FeatureManagement\Storage\SnapshotProviders\FileSnapshotProvider;
use Toggly\FeatureManagement\Storage\SnapshotSettings;

class JwkManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/toggly-jwk-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testWhitelistDeniesKeyBeforeSnapshotOrNetworkLookup(): void
    {
        $fixture = $this->keyFixture();
        $provider = $this->snapshotProvider();
        $provider->saveJwkSnapshot($fixture['jwks'], time() + 3600);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('get');

        $manager = new JwkManager($http, 'https://example.test/', $provider, ['another-key']);

        $this->assertNull($manager->getEcdsaKey($fixture['kid']));
    }

    public function testSnapshotKeyRemainsInMemoryUntilExplicitCacheClear(): void
    {
        $fixture = $this->keyFixture();
        $provider = $this->snapshotProvider();
        $provider->saveJwkSnapshot($fixture['jwks'], time() + 3600);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('get')->with('.well-known/jwks')->willReturn(null);
        $manager = new JwkManager($http, 'https://example.test/', $provider);

        $fromSnapshot = $manager->getEcdsaKey($fixture['kid']);
        $this->assertSignatureVerifies($fixture['privateKey'], $fromSnapshot);

        $provider->saveJwkSnapshot(new JsonWebKeySet(['keys' => []]), time() + 3600);
        $fromMemory = $manager->getEcdsaKey($fixture['kid']);
        $this->assertSame($fromSnapshot, $fromMemory);

        $manager->clearCache();
        $this->assertNull($manager->getEcdsaKey($fixture['kid']));
    }

    public function testApiKeyIsVerifiedPersistedAndReusedFromMemory(): void
    {
        $fixture = $this->keyFixture();
        $provider = $this->snapshotProvider();
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('get')->with('.well-known/jwks')
            ->willReturn($this->response(json_encode($fixture['document'], JSON_THROW_ON_ERROR)));
        $manager = new JwkManager($http, 'https://example.test/', $provider);

        $key = $manager->getEcdsaKey($fixture['kid']);
        $this->assertSignatureVerifies($fixture['privateKey'], $key);
        $this->assertSame($key, $manager->getEcdsaKey($fixture['kid']));

        $stored = $provider->getJwkSnapshot();
        $this->assertNotNull($stored['jwks']);
        $this->assertSame($fixture['kid'], $stored['jwks']->keys[0]->kid);
        $this->assertGreaterThan(time(), $stored['timestamp']);
    }

    public function testMissingAndMalformedApiResponsesFailClosed(): void
    {
        $fixture = $this->keyFixture();
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->exactly(2))->method('get')->with('.well-known/jwks')
            ->willReturnOnConsecutiveCalls(null, $this->response('{invalid-json'));
        $manager = new JwkManager($http, 'https://example.test/');

        $this->assertNull($manager->getEcdsaKey($fixture['kid']));
        $this->assertNull($manager->getEcdsaKey($fixture['kid']));
    }

    public function testTransportExceptionFailsClosed(): void
    {
        $fixture = $this->keyFixture();
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('get')->willThrowException(new \RuntimeException('offline'));
        $manager = new JwkManager($http, 'https://example.test/');

        $this->assertNull($manager->getEcdsaKey($fixture['kid']));
    }

    public function testTamperedSnapshotKeyIdDoesNotBlockValidApiKey(): void
    {
        $fixture = $this->keyFixture();
        $wrongKid = $fixture['document'];
        $wrongKid['keys'][0]['kid'] = 'tampered';

        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('get')->with('.well-known/jwks')
            ->willReturn($this->response(json_encode($fixture['document'], JSON_THROW_ON_ERROR)));

        $provider = $this->snapshotProvider();
        $provider->saveJwkSnapshot(new JsonWebKeySet($wrongKid), time() + 3600);
        $manager = new JwkManager($http, 'https://example.test/', $provider);
        $this->assertSignatureVerifies($fixture['privateKey'], $manager->getEcdsaKey($fixture['kid']));
    }

    public function testWrongAlgorithmIsRejected(): void
    {
        $fixture = $this->keyFixture();
        $wrongAlgorithm = $fixture['document'];
        $wrongAlgorithm['keys'][0]['alg'] = 'RS256';
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('get')->with('.well-known/jwks')->willReturn(null);
        $provider = $this->snapshotProvider();

        $provider->saveJwkSnapshot(new JsonWebKeySet($wrongAlgorithm), time() + 3600);
        $manager = new JwkManager($http, 'https://example.test/', $provider);
        $this->assertNull($manager->getEcdsaKey($fixture['kid']));
    }

    public function testInvalidCurvePointIsRejected(): void
    {
        $coordinate = str_repeat("\x00", 32);
        $kid = strtoupper(sha1($coordinate . $coordinate)) . 'ES256';
        $document = ['keys' => [[
            'kty' => 'EC',
            'use' => 'sig',
            'kid' => $kid,
            'crv' => 'P-256',
            'x' => rtrim(strtr(base64_encode($coordinate), '+/', '-_'), '='),
            'y' => rtrim(strtr(base64_encode($coordinate), '+/', '-_'), '='),
            'alg' => 'ES256',
        ]]];
        $provider = $this->snapshotProvider();
        $provider->saveJwkSnapshot(new JsonWebKeySet($document), time() + 3600);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('get')->with('.well-known/jwks')->willReturn(null);

        $manager = new JwkManager($http, 'https://example.test/', $provider);

        $this->assertNull($manager->getEcdsaKey($kid));
    }

    private function snapshotProvider(): FileSnapshotProvider
    {
        return new FileSnapshotProvider($this->directory, new SnapshotSettings([
            'jwk_document_name' => 'jwks.json',
        ]));
    }

    /** @return array{kid: string, privateKey: mixed, jwks: JsonWebKeySet, document: array} */
    private function keyFixture(): array
    {
        $privateKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $kid = strtoupper(sha1($x . $y)) . 'ES256';
        $document = ['keys' => [[
            'kty' => 'EC',
            'use' => 'sig',
            'kid' => $kid,
            'crv' => 'P-256',
            'x' => rtrim(strtr(base64_encode($x), '+/', '-_'), '='),
            'y' => rtrim(strtr(base64_encode($y), '+/', '-_'), '='),
            'alg' => 'ES256',
        ]]];

        return [
            'kid' => $kid,
            'privateKey' => $privateKey,
            'jwks' => new JsonWebKeySet($document),
            'document' => $document,
        ];
    }

    private function response(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }

    private function assertSignatureVerifies($privateKey, $publicKey): void
    {
        $this->assertTrue(
            is_resource($publicKey) || $publicKey instanceof \OpenSSLAsymmetricKey,
            'JWK resolution must return an OpenSSL public key'
        );
        $signature = '';
        $this->assertTrue(openssl_sign('jwk-resolution-regression', $signature, $privateKey, OPENSSL_ALGO_SHA256));
        $this->assertSame(1, openssl_verify('jwk-resolution-regression', $signature, $publicKey, OPENSSL_ALGO_SHA256));
    }
}
