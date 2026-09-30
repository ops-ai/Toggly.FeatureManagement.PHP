<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
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

    public function testWeakAndStrongEquivalentEtagsKeepTheVariantResponseUnparsed(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->never())->method('getBody');
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('clearETag');
        $http->expects($this->once())->method('get')
            ->with('evaluated-variants-signed/app/Production')
            ->willReturn($response);
        $http->expects($this->exactly(2))->method('getLastETag')
            ->willReturnOnConsecutiveCalls('W/"revision"', '"revision"');

        $provider = $this->provider($http, null);
        $provider->refreshFeatures();

        $this->assertNotNull($provider->getDebugInfo()['last_refresh']);
        $this->assertFalse($provider->getDebugInfo()['loaded']);
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
            $this->createStub(FeatureStateServiceInterface::class)
        );
    }
}
