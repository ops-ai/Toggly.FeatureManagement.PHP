<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureSnapshotProviderInterface;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Exceptions\SignatureVerificationException;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Security\EcdsaSignatureVerifier;

final class FeatureProviderSignedSnapshotAuthenticationTest extends TestCase
{
    public function testVerifiedSnapshotLoadsOnlyAfterExactRawBytesAreAuthenticated(): void
    {
        $rawDefs = '[{"featureKey":"signed-checkout","filters":[{"name":"AlwaysOn"}]}]';
        $feature = new FeatureDefinition(['featureKey' => 'signed-checkout', 'filters' => [['name' => 'AlwaysOn']]]);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('setLastETag')->with('"signed-revision"');
        $verifier = $this->createMock(EcdsaSignatureVerifier::class);
        $verifier->expects($this->once())->method('verifySnapshot')
            ->with($rawDefs, 'signature', 'kid-1', 123)
            ->willReturn(true);

        $provider = $this->loadSignedSnapshot(
            $http,
            $verifier,
            $this->createStub(LoggerInterface::class),
            $this->snapshot([$feature], 'signature', 'kid-1', 123, $rawDefs, '"signed-revision"')
        );

        $this->assertTrue($provider->getDebugInfo()['loaded']);
        $this->assertSame($feature, $provider->getFeatureDefinition('signed-checkout'));
    }

    public function testMissingSignedSnapshotFieldsFailClosedBeforeVerification(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Snapshot is missing required signature fields');
        $verifier = $this->createMock(EcdsaSignatureVerifier::class);
        $verifier->expects($this->never())->method('verifySnapshot');

        $provider = $this->loadSignedSnapshot(
            $this->createStub(TogglyHttpClient::class),
            $verifier,
            $logger,
            $this->snapshot([new FeatureDefinition(['featureKey' => 'blocked'])], null, 'kid-1', 123, '[]', null)
        );

        $this->assertFalse($provider->getDebugInfo()['loaded']);
        $this->assertSame('Snapshot is missing required signature fields', $provider->getDebugInfo()['last_error']);
    }

    public function testInvalidSnapshotSignatureFailsClosed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Invalid signature in snapshot');
        $verifier = $this->createMock(EcdsaSignatureVerifier::class);
        $verifier->expects($this->once())->method('verifySnapshot')->willReturn(false);

        $provider = $this->loadSignedSnapshot(
            $this->createStub(TogglyHttpClient::class),
            $verifier,
            $logger,
            $this->snapshot([new FeatureDefinition(['featureKey' => 'blocked'])], 'signature', 'kid-1', 123, '[]', null)
        );

        $this->assertFalse($provider->getDebugInfo()['loaded']);
        $this->assertSame('Invalid signature in snapshot', $provider->getDebugInfo()['last_error']);
    }

    public function testThrownSnapshotVerificationFailureIsContained(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Signature verification failed for snapshot', $this->anything());
        $verifier = $this->createMock(EcdsaSignatureVerifier::class);
        $verifier->expects($this->once())->method('verifySnapshot')
            ->willThrowException(new SignatureVerificationException('bad snapshot signature'));

        $provider = $this->loadSignedSnapshot(
            $this->createStub(TogglyHttpClient::class),
            $verifier,
            $logger,
            $this->snapshot([new FeatureDefinition(['featureKey' => 'blocked'])], 'signature', 'kid-1', 123, '[]', null)
        );

        $this->assertFalse($provider->getDebugInfo()['loaded']);
        $this->assertSame('Signature verification failed for snapshot', $provider->getDebugInfo()['last_error']);
    }

    public function testLegacySnapshotWithoutRawBytesWarnsAndRemainsAvailable(): void
    {
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->willReturnCallback(
            static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            }
        );
        $verifier = $this->createMock(EcdsaSignatureVerifier::class);
        $verifier->expects($this->never())->method('verifySnapshot');
        $feature = new FeatureDefinition(['featureKey' => 'legacy-checkout']);

        $provider = $this->loadSignedSnapshot(
            $this->createStub(TogglyHttpClient::class),
            $verifier,
            $logger,
            $this->snapshot([$feature], 'signature', 'kid-1', 123, null, null)
        );

        $this->assertSame([$this->legacyWarning()], $warnings);
        $this->assertTrue($provider->getDebugInfo()['loaded']);
        $this->assertSame($feature, $provider->getFeatureDefinition('legacy-checkout'));
    }

    /** @param FeatureDefinition[] $features */
    private function snapshot(
        array $features,
        ?string $signature,
        ?string $keyId,
        ?int $timestamp,
        ?string $signedDefsJson,
        ?string $etag
    ): array {
        return [
            'features' => $features,
            'signature' => $signature,
            'keyId' => $keyId,
            'timestamp' => $timestamp,
            'signedDefsJson' => $signedDefsJson,
            'etag' => $etag,
        ];
    }

    private function loadSignedSnapshot(
        TogglyHttpClient $http,
        EcdsaSignatureVerifier $verifier,
        LoggerInterface $logger,
        array $snapshotData
    ): FeatureProvider {
        $snapshot = $this->createMock(FeatureSnapshotProviderInterface::class);
        $snapshot->expects($this->exactly(2))->method('getFeaturesSnapshot')->willReturnOnConsecutiveCalls(
            $this->snapshot([], null, null, null, null, null),
            $snapshotData
        );
        $provider = new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'use_signed_definitions' => true,
                'enable_live_updates' => false,
            ]),
            $http,
            $this->createStub(FeatureStateServiceInterface::class),
            $snapshot,
            $logger
        );
        $this->setPrivateProperty($provider, 'signatureVerifier', $verifier);
        $loadSnapshot = new \ReflectionMethod($provider, 'loadSnapshot');
        if (PHP_VERSION_ID < 80100) {
            $loadSnapshot->setAccessible(true);
        }
        $loadSnapshot->invoke($provider);

        return $provider;
    }

    /** @param mixed $value */
    private function setPrivateProperty(object $object, string $property, $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $reflection->setValue($object, $value);
    }

    private function legacyWarning(): string
    {
        return 'Snapshot is missing signedDefsJson; loaded without cryptographic re-verification. Clear and refresh to upgrade the snapshot.';
    }
}
