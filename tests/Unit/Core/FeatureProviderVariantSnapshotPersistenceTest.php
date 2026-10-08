<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureSnapshotProviderInterface;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Models\JsonWebKeySet;

final class FeatureProviderVariantSnapshotPersistenceTest extends TestCase
{
    public function testEvaluatedVariantsPersistRawDefinitionsAndReloadFeatureState(): void
    {
        $data = [
            'defs' => [
                'checkout' => [
                    'enabled' => true,
                    'variant' => 'compact',
                    'configurationValue' => ['headline' => 'Shorter checkout'],
                ],
                'beta-banner' => [
                    'enabled' => false,
                    'variant' => 'control',
                    'configurationValue' => 'not exposed',
                ],
            ],
        ];
        $body = '{"defs":' . json_encode($data['defs'], JSON_THROW_ON_ERROR) . '}';
        $snapshot = new class implements FeatureSnapshotProviderInterface {
            /** @var array<string, mixed>|null */
            public ?array $saved = null;

            public function saveSnapshot(
                array $features,
                ?string $signature = null,
                ?string $keyId = null,
                ?int $timestamp = null,
                ?string $signedDefsJson = null,
                ?string $etag = null
            ): void {
                $this->saved = compact('features', 'signature', 'keyId', 'timestamp', 'signedDefsJson', 'etag');
            }

            public function getFeaturesSnapshot(): array
            {
                if ($this->saved === null) {
                    return [
                        'features' => null,
                        'signature' => null,
                        'keyId' => null,
                        'timestamp' => null,
                        'signedDefsJson' => null,
                        'etag' => null,
                    ];
                }

                return $this->saved;
            }

            public function saveJwkSnapshot(JsonWebKeySet $jwks, int $timestamp): void {}
            public function getJwkSnapshot(): array { return ['jwks' => null, 'timestamp' => null]; }
            public function clear(): void {}
        };
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('get')
            ->with('evaluated-variants-signed/app/Production')
            ->willReturn($this->response($body));
        $http->expects($this->never())->method('clearETag');
        $http->expects($this->exactly(3))->method('getLastETag')
            ->willReturnOnConsecutiveCalls(null, '"revision-1"', '"revision-1"');

        $provider = $this->provider($http, $snapshot);
        $provider->refreshFeatures();

        $this->assertSame(
            ['name' => 'compact', 'configurationValue' => ['headline' => 'Shorter checkout']],
            $provider->getVariant('checkout')
        );
        $this->assertSame($data['defs'], json_decode($snapshot->saved['signedDefsJson'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([null, null, null, '"revision-1"'], [
            $snapshot->saved['signature'],
            $snapshot->saved['keyId'],
            $snapshot->saved['timestamp'],
            $snapshot->saved['etag'],
        ]);
        $this->assertSame('AlwaysOn', $snapshot->saved['features'][0]->filters[0]->name);
        $this->assertSame('AlwaysOff', $snapshot->saved['features'][1]->filters[0]->name);

        $reloadingHttp = $this->createMock(TogglyHttpClient::class);
        $reloadingHttp->expects($this->once())->method('setLastETag')->with('"revision-1"');
        $reloadingHttp->expects($this->never())->method('get');
        $reloaded = $this->provider($reloadingHttp, $snapshot);

        $this->assertSame('AlwaysOn', $reloaded->getFeatureDefinition('checkout')->filters[0]->name);
        $this->assertSame('AlwaysOff', $reloaded->getFeatureDefinition('beta-banner')->filters[0]->name);
        $this->assertSame(['name' => 'compact', 'configurationValue' => ['headline' => 'Shorter checkout']], $reloaded->getVariant('checkout'));
        $this->assertSame(['headline' => 'Shorter checkout'], $reloaded->getVariantValue('checkout'));
        $this->assertNull($reloaded->getVariant('beta-banner'));
        $this->assertNull($reloaded->getVariantValue('beta-banner'));
    }

    public function testMalformedPersistedVariantDefinitionsKeepFeatureSnapshotAndExposeNoVariant(): void
    {
        $snapshot = new class implements FeatureSnapshotProviderInterface {
            public function saveSnapshot(
                array $features,
                ?string $signature = null,
                ?string $keyId = null,
                ?int $timestamp = null,
                ?string $signedDefsJson = null,
                ?string $etag = null
            ): void {}

            public function getFeaturesSnapshot(): array
            {
                return [
                    'features' => [new \Toggly\FeatureManagement\Models\FeatureDefinition([
                        'featureKey' => 'checkout',
                        'filters' => [['name' => 'AlwaysOn']],
                    ])],
                    'signature' => null,
                    'keyId' => null,
                    'timestamp' => null,
                    'signedDefsJson' => '{malformed',
                    'etag' => null,
                ];
            }

            public function saveJwkSnapshot(JsonWebKeySet $jwks, int $timestamp): void {}
            public function getJwkSnapshot(): array { return ['jwks' => null, 'timestamp' => null]; }
            public function clear(): void {}
        };
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('get');

        $provider = $this->provider($http, $snapshot);

        $this->assertSame('AlwaysOn', $provider->getFeatureDefinition('checkout')->filters[0]->name);
        $this->assertNull($provider->getVariant('checkout'));
        $this->assertNull($provider->getVariantValue('checkout'));
    }

    private function provider(TogglyHttpClient $http, FeatureSnapshotProviderInterface $snapshot): FeatureProvider
    {
        return new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'enable_variants' => true,
                'enable_live_updates' => false,
                'use_signed_definitions' => false,
            ]),
            $http,
            $this->createStub(FeatureStateServiceInterface::class),
            $snapshot
        );
    }

    private function response(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('rewind');
        $stream->method('getContents')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
