<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Exceptions\SignatureVerificationException;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Security\EcdsaSignatureVerifier;

final class FeatureProviderSignedEvaluatedVariantsAuthenticationTest extends TestCase
{
    public function testStaleSignedVariantResponsePreservesLastKnownGoodVariantWithoutVerification(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Received variant definitions with older timestamp',
            ['current' => 20, 'received' => 19]
        );
        $verifier = $this->createMock(EcdsaSignatureVerifier::class);
        $verifier->expects($this->once())->method('verify')->willReturn(true);
        $provider = $this->provider($logger, $verifier);

        $this->assertTrue($this->process($provider, $this->body('current', 20), $this->data('current', 20)));
        $this->assertFalse($this->process($provider, $this->body('stale', 19), $this->data('stale', 19)));

        $this->assertSame(['name' => 'current', 'configurationValue' => ['revision' => 20]], $provider->getVariant('checkout'));
        $this->assertNull($provider->getVariant('new-checkout'));
    }

    public function testInvalidSignedVariantResponseFailsClosedWithExactRawDefinitionsAndKeepsLastKnownGood(): void
    {
        $currentBody = $this->body('current', 20);
        $invalidRawDefs = '{ "new-checkout" : { "enabled" : true, "variant" : "new", "configurationValue" : { "revision" : 21 } } }';
        $invalidBody = sprintf('{"defs":%s,"signature":"bad","kid":"kid-1","timestamp":21}', $invalidRawDefs);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Invalid signature on evaluated-variants response');
        $verifier = $this->createMock(EcdsaSignatureVerifier::class);
        $verifier->expects($this->exactly(2))->method('verify')->willReturnCallback(
            function (string $rawDefs, string $signature, string $kid, int $timestamp) use ($currentBody, $invalidRawDefs): bool {
                if ($signature === 'sig') {
                    $this->assertSame($this->rawDefs($currentBody), $rawDefs);
                    $this->assertSame('kid-1', $kid);
                    $this->assertSame(20, $timestamp);
                    return true;
                }

                $this->assertSame($invalidRawDefs, $rawDefs);
                $this->assertSame('kid-1', $kid);
                $this->assertSame(21, $timestamp);
                return false;
            }
        );
        $provider = $this->provider($logger, $verifier);

        $this->assertTrue($this->process($provider, $currentBody, $this->data('current', 20)));
        $this->assertFalse($this->process($provider, $invalidBody, $this->data('new', 21, 'bad', 'new-checkout')));

        $this->assertSame(['name' => 'current', 'configurationValue' => ['revision' => 20]], $provider->getVariant('checkout'));
        $this->assertNull($provider->getVariant('new-checkout'));
    }

    public function testThrowingSignedVariantVerifierFailsClosedAndKeepsLastKnownGood(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Signature verification failed for evaluated-variants',
            ['error' => 'variant signature unavailable']
        );
        $verifier = $this->createMock(EcdsaSignatureVerifier::class);
        $verifier->expects($this->exactly(2))->method('verify')->willReturnOnConsecutiveCalls(
            true,
            $this->throwException(new SignatureVerificationException('variant signature unavailable'))
        );
        $provider = $this->provider($logger, $verifier);

        $this->assertTrue($this->process($provider, $this->body('current', 20), $this->data('current', 20)));
        $this->assertFalse($this->process($provider, $this->body('replacement', 21), $this->data('replacement', 21)));

        $this->assertSame(['name' => 'current', 'configurationValue' => ['revision' => 20]], $provider->getVariant('checkout'));
    }

    private function provider(LoggerInterface $logger, EcdsaSignatureVerifier $verifier): FeatureProvider
    {
        $http = $this->createStub(TogglyHttpClient::class);
        $provider = new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'enable_variants' => true,
                'enable_live_updates' => false,
                'use_signed_definitions' => true,
            ]),
            $http,
            $this->createStub(FeatureStateServiceInterface::class),
            null,
            $logger
        );
        $property = new \ReflectionProperty($provider, 'signatureVerifier');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($provider, $verifier);

        return $provider;
    }

    private function process(FeatureProvider $provider, string $body, array $data): bool
    {
        $method = new \ReflectionMethod($provider, 'processEvaluatedVariantsResponse');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke($provider, $body, $data);
    }

    private function data(string $variant, int $timestamp, string $signature = 'sig', string $featureKey = 'checkout'): array
    {
        return [
            'defs' => [
                $featureKey => [
                    'enabled' => true,
                    'variant' => $variant,
                    'configurationValue' => ['revision' => $timestamp],
                ],
            ],
            'signature' => $signature,
            'kid' => 'kid-1',
            'timestamp' => $timestamp,
        ];
    }

    private function body(string $variant, int $timestamp): string
    {
        return sprintf(
            '{"defs":{"checkout":{"enabled":true,"variant":"%s","configurationValue":{"revision":%d}}},"signature":"sig","kid":"kid-1","timestamp":%d}',
            $variant,
            $timestamp,
            $timestamp
        );
    }

    private function rawDefs(string $body): string
    {
        preg_match('/"defs":(\{.*\}),"signature"/', $body, $matches);
        return $matches[1];
    }
}
