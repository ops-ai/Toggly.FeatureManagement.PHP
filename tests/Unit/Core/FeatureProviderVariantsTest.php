<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;

final class FeatureProviderVariantsTest extends TestCase
{
    public function testAppliesEnabledVariantsAndKeepsDisabledVariantsUnavailable(): void
    {
        $provider = $this->provider();
        $data = [
            'defs' => [
                'checkout-layout' => [
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
            'timestamp' => 42,
        ];

        $this->assertTrue($this->process($provider, json_encode($data, JSON_THROW_ON_ERROR), $data));
        $this->assertSame(
            ['name' => 'compact', 'configurationValue' => ['headline' => 'Shorter checkout']],
            $provider->getVariant('checkout-layout')
        );
        $this->assertSame(['headline' => 'Shorter checkout'], $provider->getVariantValue('checkout-layout'));
        $this->assertNull($provider->getVariant('beta-banner'));
        $this->assertNull($provider->getVariant('unknown'));
        $this->assertSame('AlwaysOn', $provider->getFeatureDefinition('checkout-layout')->filters[0]->name);
        $this->assertSame('AlwaysOff', $provider->getFeatureDefinition('beta-banner')->filters[0]->name);
    }

    public function testRejectsResponseWithoutDefinitionsAndLeavesProviderUnloaded(): void
    {
        $provider = $this->provider();
        $data = ['timestamp' => 42];

        $this->assertFalse($this->process($provider, json_encode($data, JSON_THROW_ON_ERROR), $data));
        $this->assertFalse($provider->getDebugInfo()['loaded']);
        $this->assertSame([], $provider->getAllFeatureDefinitions());
    }

    public function testSkipsMalformedDefinitionRowsAndClearsVariantsRemovedByNextRevision(): void
    {
        $provider = $this->provider();
        $initial = ['defs' => ['old-variant' => ['enabled' => true, 'variant' => 'old']], 'timestamp' => 1];
        $replacement = [
            'defs' => [
                'malformed' => 'not an object',
                'new-variant' => ['enabled' => true, 'variant' => 'new', 'configurationValue' => null],
            ],
            'timestamp' => 2,
        ];

        $this->assertTrue($this->process($provider, json_encode($initial, JSON_THROW_ON_ERROR), $initial));
        $this->assertSame(['name' => 'old', 'configurationValue' => null], $provider->getVariant('old-variant'));

        $this->assertTrue($this->process($provider, json_encode($replacement, JSON_THROW_ON_ERROR), $replacement));
        $this->assertNull($provider->getVariant('old-variant'));
        $this->assertNull($provider->getVariant('malformed'));
        $this->assertSame(['name' => 'new', 'configurationValue' => null], $provider->getVariant('new-variant'));
        $this->assertNull($provider->getFeatureDefinition('malformed'));
    }

    private function provider(): FeatureProvider
    {
        return new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'enable_variants' => true,
                'enable_live_updates' => false,
                'use_signed_definitions' => false,
            ]),
            $this->createStub(TogglyHttpClient::class),
            $this->createStub(FeatureStateServiceInterface::class)
        );
    }

    private function process(FeatureProvider $provider, string $body, array $data): bool
    {
        $method = new \ReflectionMethod($provider, 'processEvaluatedVariantsResponse');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke($provider, $body, $data);
    }
}
