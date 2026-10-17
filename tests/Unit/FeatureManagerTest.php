<?php

namespace Toggly\FeatureManagement\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\SecureFeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\FeatureManagement\Telemetry\FeatureUsageScope;

/**
 * Basic tests for settings and FeatureManager usage-scope delegation.
 */
class FeatureManagerTest extends TestCase
{
    public function testSettingsCanBeInstantiated(): void
    {
        $settings = new TogglySettings(['app_key' => 'test-app-key', 'environment' => 'Production']);

        $this->assertInstanceOf(TogglySettings::class, $settings);
    }

    public function testSettingsAreStoredCorrectly(): void
    {
        $appKey = 'test-app-key-123';
        $environment = 'Production';
        $settings = new TogglySettings(['app_key' => $appKey, 'environment' => $environment]);

        $this->assertEquals($appKey, $settings->appKey);
        $this->assertEquals($environment, $settings->environment);
    }

    public function testSettingsHaveDefaultValues(): void
    {
        $settings = new TogglySettings(['app_key' => 'app-key', 'environment' => 'Production']);

        $this->assertNotNull($settings->getBaseUrl());
        $this->assertIsString($settings->getBaseUrl());
    }

    public function testUseDelegatesToUsageStatsProvider(): void
    {
        $usage = $this->createMock(UsageStatsProviderInterface::class);
        $usage->expects($this->once())
            ->method('use')
            ->with('Checkout', $this->isType('callable'), 'treatment')
            ->willReturnCallback(static function (string $featureKey, callable $action, string $variant) {
                return $action(FeatureUsageScope::noop());
            });

        $manager = new FeatureManager(
            $this->createMock(FeatureProviderInterface::class),
            $usage,
            $this->createMock(SecureFeatureProviderInterface::class)
        );

        $result = $manager->use('Checkout', static function (FeatureUsageScope $scope) {
            return 'done';
        }, 'treatment');

        $this->assertSame('done', $result);
    }
}
