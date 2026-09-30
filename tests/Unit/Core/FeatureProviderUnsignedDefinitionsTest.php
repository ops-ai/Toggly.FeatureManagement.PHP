<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Core\FeatureStateService;
use Toggly\FeatureManagement\Http\TogglyHttpClient;

final class FeatureProviderUnsignedDefinitionsTest extends TestCase
{
    public function testUnsignedRefreshMapsMetricsSecureStateAndDefinitionNotifications(): void
    {
        $http = $this->http('[
            {"featureKey":"checkout","filters":[{"name":"AlwaysOn"}],"metrics":["purchase","funnel"],"securedFeature":true},
            {"featureKey":"banner","filters":[{"name":"AlwaysOff"}],"metrics":["purchase"],"securedFeature":false},
            {"featureKey":"no-metrics","filters":[]}
        ]');
        $state = new FeatureStateService();
        $turnOns = 0;
        $turnOffs = 0;
        $definitionChanges = 0;
        $state->whenFeatureTurnsOn('checkout', static function () use (&$turnOns): void { $turnOns++; });
        $state->whenFeatureTurnsOff('banner', static function () use (&$turnOffs): void { $turnOffs++; });
        $state->whenDefinitionsChange(static function () use (&$definitionChanges): void { $definitionChanges++; });
        $provider = $this->provider($http, $state);

        $provider->refreshFeatures();

        $this->assertSame(['checkout', 'banner'], $provider->getFeaturesForMetric('purchase'));
        $this->assertSame(['checkout'], $provider->getFeaturesForMetric('funnel'));
        $this->assertNull($provider->getFeaturesForMetric('absent'));
        $this->assertTrue($provider->isFeatureSecured('checkout'));
        $this->assertFalse($provider->isFeatureSecured('banner'));
        $this->assertSame('AlwaysOn', $provider->getFeatureDefinition('checkout')->filters[0]->name);
        $this->assertSame(1, $turnOns);
        $this->assertSame(1, $turnOffs);
        $this->assertSame(1, $definitionChanges);
    }

    public function testNextUnsignedRefreshReplacesMetricIndexAndClearsSecuredFlag(): void
    {
        $http = $this->http(
            '[{"featureKey":"checkout","filters":[{"name":"AlwaysOn"}],"metrics":["purchase"],"securedFeature":true}]',
            '[{"featureKey":"checkout","filters":[{"name":"AlwaysOff"}],"metrics":["retention"],"securedFeature":false}]'
        );
        $provider = $this->provider($http, new FeatureStateService());

        $provider->refreshFeatures();
        $provider->refreshFeatures();

        $this->assertNull($provider->getFeaturesForMetric('purchase'));
        $this->assertSame(['checkout'], $provider->getFeaturesForMetric('retention'));
        $this->assertFalse($provider->isFeatureSecured('checkout'));
        $this->assertSame('AlwaysOff', $provider->getFeatureDefinition('checkout')->filters[0]->name);
    }

    public function testUndefinedDefinitionsAreEnabledOnlyWhenDevelopmentDefaultIsConfigured(): void
    {
        $development = $this->provider($this->http('[]'), new FeatureStateService(), true);
        $production = $this->provider($this->http('[]'), new FeatureStateService(), false);

        $development->refreshFeatures();
        $production->refreshFeatures();

        $this->assertSame('AlwaysOn', $development->getFeatureDefinition('new-feature')->filters[0]->name);
        $this->assertNull($production->getFeatureDefinition('new-feature'));
    }

    private function provider(TogglyHttpClient $http, FeatureStateService $state, bool $undefinedEnabledOnDevelopment = false): FeatureProvider
    {
        return new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'enable_live_updates' => false,
                'use_signed_definitions' => false,
                'undefined_enabled_on_development' => $undefinedEnabledOnDevelopment,
            ]),
            $http,
            $state
        );
    }

    private function http(string ...$bodies): TogglyHttpClient
    {
        $responses = array_map(fn (string $body): ResponseInterface => $this->response($body), $bodies);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->exactly(count($responses)))->method('get')->with('definitions/app/Production')
            ->willReturnOnConsecutiveCalls(...$responses);
        $http->method('getLastETag')->willReturn(null);

        return $http;
    }

    private function response(string $json): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('rewind');
        $stream->method('getContents')->willReturn($json);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
