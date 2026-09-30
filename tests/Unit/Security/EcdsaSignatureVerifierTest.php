<?php

namespace Toggly\FeatureManagement\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Toggly\FeatureManagement\Exceptions\SignatureVerificationException;
use Toggly\FeatureManagement\Security\EcdsaSignatureVerifier;
use Toggly\FeatureManagement\Security\JwkManager;

final class EcdsaSignatureVerifierTest extends TestCase
{
    public function testAcceptsBothDerAndP1363Signatures(): void
    {
        [$private, $public] = $this->keyPair();
        $data = '{"featureKey":"checkout"}';
        $timestamp = 17;
        $der = $this->sign($private, $data, $timestamp);
        $jwkManager = $this->createMock(JwkManager::class);
        $jwkManager->expects($this->exactly(2))->method('getEcdsaKey')->with('key-1')->willReturn($public);
        $verifier = new EcdsaSignatureVerifier($jwkManager);

        $this->assertTrue($verifier->verify($data, base64_encode($der), 'key-1', $timestamp));
        $this->assertTrue($verifier->verify($data, base64_encode($this->derToP1363($der)), 'key-1', $timestamp));
    }

    public function testRejectsMalformedAndCryptographicallyInvalidSignatures(): void
    {
        [$private, $public] = $this->keyPair();
        $jwkManager = $this->createMock(JwkManager::class);
        $jwkManager->method('getEcdsaKey')->willReturn($public);
        $verifier = new EcdsaSignatureVerifier($jwkManager);

        try {
            $verifier->verify('payload', '***not-base64***', 'key-1', 1);
            $this->fail('Malformed base64 must fail closed.');
        } catch (SignatureVerificationException $exception) {
            $this->assertSame('Invalid base64 signature', $exception->getMessage());
        }

        $signature = $this->sign($private, 'original', 1);
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Invalid signature');
        $verifier->verify('tampered', base64_encode($signature), 'key-1', 1);
    }

    public function testRejectsMissingKeyAndWrapsUnexpectedLookupFailures(): void
    {
        $missing = $this->createMock(JwkManager::class);
        $missing->method('getEcdsaKey')->willReturn(null);
        $verifier = new EcdsaSignatureVerifier($missing);
        try {
            $verifier->verify('payload', base64_encode('signature'), 'missing', 1);
            $this->fail('Missing signing keys must fail closed.');
        } catch (SignatureVerificationException $exception) {
            $this->assertSame('No ES256 key found for key ID: missing', $exception->getMessage());
        }

        $failing = $this->createMock(JwkManager::class);
        $failing->method('getEcdsaKey')->willThrowException(new \RuntimeException('JWK backend unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Signature verification failed', $this->callback(
            static fn (array $context): bool => $context['keyId'] === 'key-1' && $context['error'] === 'JWK backend unavailable'
        ));
        $verifier = new EcdsaSignatureVerifier($failing, $logger);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Signature verification failed: JWK backend unavailable');
        $verifier->verifySnapshot('payload', base64_encode('signature'), 'key-1', 1);
    }

    private function keyPair(): array
    {
        $private = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        $this->assertIsArray($details);
        $public = openssl_pkey_get_public($details['key']);
        $this->assertNotFalse($public);

        return [$private, $public];
    }

    private function sign($private, string $data, int $timestamp): string
    {
        $signature = '';
        $this->assertTrue(openssl_sign(hash('sha256', $data . '|' . $timestamp, true), $signature, $private, OPENSSL_ALGO_SHA256));
        return $signature;
    }

    private function derToP1363(string $der): string
    {
        $this->assertSame("\x30", $der[0]);
        $offset = 2;
        $this->assertSame("\x02", $der[$offset++]);
        $rLength = ord($der[$offset++]);
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;
        $this->assertSame("\x02", $der[$offset++]);
        $sLength = ord($der[$offset++]);
        $s = substr($der, $offset, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
