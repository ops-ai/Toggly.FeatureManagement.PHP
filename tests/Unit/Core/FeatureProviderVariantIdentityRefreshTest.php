<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Core\FeatureStateService;
use Toggly\FeatureManagement\Http\TogglyHttpClient;

final class FeatureProviderVariantIdentityRefreshTest extends TestCase
{
    public function testIdentityOverrideIsEncodedAndClearsSharedEtagOnlyWhenIdentityChanges(): void
    {
        $paths = [];
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->exactly(3))->method('clearETag');
        $http->expects($this->exactly(4))->method('get')->willReturnCallback(
            static function (string $path) use (&$paths) {
                $paths[] = $path;
                return null;
            }
        );
        $http->method('getLastETag')->willReturn(null);

        $provider = $this->provider($http, 'configured user');
        $provider->refreshFeatures();
        $provider->refreshFeatures();
        $provider->setIdentity('request/user+id');
        $provider->refreshFeatures();
        $provider->setIdentity('');
        $provider->refreshFeatures();

        $this->assertSame([
            'evaluated-variants-signed/app/Production?userId=configured%20user',
            'evaluated-variants-signed/app/Production?userId=configured%20user',
            'evaluated-variants-signed/app/Production?userId=request%2Fuser%2Bid',
            'evaluated-variants-signed/app/Production?userId=configured%20user',
        ], $paths);
    }

    public function testAnonymousVariantRefreshUsesNoIdentityQueryAndKeepsExistingEtag(): void
    {
        $paths = [];
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('clearETag');
        $http->expects($this->exactly(2))->method('get')->willReturnCallback(
            static function (string $path) use (&$paths) {
                $paths[] = $path;
                return null;
            }
        );
        $http->method('getLastETag')->willReturn(null);

        $provider = $this->provider($http, null);
        $provider->refreshFeatures();
        $provider->setIdentity(null);
        $provider->refreshFeatures();

        $this->assertSame([
            'evaluated-variants-signed/app/Production',
            'evaluated-variants-signed/app/Production',
        ], $paths);
    }

    public function testWeakAndStrongEquivalentEtagsStillApplyVariantResponseBody(): void
    {
        $body = json_encode([
            'defs' => [
                'flag-a' => ['enabled' => true, 'variant' => 'control'],
            ],
            'timestamp' => 1,
        ], JSON_THROW_ON_ERROR);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('clearETag');
        $http->expects($this->once())->method('get')
            ->with('evaluated-variants-signed/app/Production')
            ->willReturn($this->jsonResponse($body, '"revision"'));
        $etagCalls = 0;
        $http->method('getLastETag')->willReturnCallback(static function () use (&$etagCalls) {
            $etagCalls++;
            return $etagCalls === 1 ? 'W/"revision"' : '"revision"';
        });

        $provider = $this->provider($http, null);
        $provider->refreshFeatures();

        $debug = $provider->getDebugInfo();
        $this->assertNull($debug['last_error'], (string) $debug['last_error']);
        $this->assertNotNull($debug['last_refresh']);
        $this->assertTrue($debug['loaded']);
        $this->assertNotNull($provider->getVariant('flag-a'));
    }

    private function jsonResponse(string $body, string $etag): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('rewind');
        $stream->method('getContents')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaderLine')->willReturnCallback(static function (string $name) use ($etag) {
            return strcasecmp($name, 'ETag') === 0 ? $etag : '';
        });

        return $response;
    }

    private function provider(TogglyHttpClient $http, ?string $identity): FeatureProvider
    {
        return new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'identity' => $identity,
                'enable_variants' => true,
                'enable_live_updates' => false,
                'use_signed_definitions' => false,
            ]),
            $http,
            new FeatureStateService()
        );
    }
}
