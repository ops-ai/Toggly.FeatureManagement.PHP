<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\SecureFeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\FeatureManagement\Models\FeatureDefinition;

/**
 * Identity, groups, and request fields are cast before comparison.
 * These checks go through FeatureManager::isEnabled so a numeric id,
 * a null group slot, or a numeric country cannot start throwing.
 */
final class FeatureManagerNonStringAudienceTest extends TestCase
{
    public function testNumericIdentityMatchesNamedAudienceUser(): void
    {
        $manager = $this->manager($this->targetingDefinition([
            'Audience.Users:0' => '42',
            'Audience.DefaultRolloutPercentage' => 0,
        ]));

        $this->assertTrue($manager->isEnabled('audience-flag', ['identity' => 42]));
    }

    public function testNumericIdentityDoesNotMatchDifferentNamedAudienceUser(): void
    {
        $manager = $this->manager($this->targetingDefinition([
            'Audience.Users:0' => 'alice',
            'Audience.DefaultRolloutPercentage' => 0,
        ]));

        $this->assertFalse($manager->isEnabled('audience-flag', ['identity' => 42]));
    }

    public function testNullGroupSlotDoesNotThrowAndRealGroupStillMatches(): void
    {
        $manager = $this->manager($this->targetingDefinition([
            'Audience.Groups:0' => 'beta',
            'Audience.DefaultRolloutPercentage' => 0,
        ]));

        $this->assertTrue($manager->isEnabled('audience-flag', [
            'groups' => [null, 'beta'],
        ]));
    }

    public function testNullGroupSlotAloneDoesNotMatchNamedAudience(): void
    {
        $manager = $this->manager($this->targetingDefinition([
            'Audience.Groups:0' => 'beta',
            'Audience.DefaultRolloutPercentage' => 0,
        ]));

        $this->assertFalse($manager->isEnabled('audience-flag', [
            'groups' => [null],
        ]));
    }

    public function testNumericCountryDoesNotThrow(): void
    {
        $manager = $this->manager(new FeatureDefinition([
            'featureKey' => 'audience-flag',
            'requirementType' => 'Any',
            'filters' => [[
                'name' => 'Country',
                'parameters' => [
                    'Percentage' => 100,
                    'Country:0' => 'us',
                ],
            ]],
        ]));

        // (string) 1 is "1", which does not equal country "us".
        $this->assertFalse($manager->isEnabled('audience-flag', [
            'request' => ['country' => 1],
        ]));

        // A value that casts to "US" still matches country "us".
        $this->assertTrue($manager->isEnabled('audience-flag', [
            'request' => ['country' => 'US'],
        ]));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function targetingDefinition(array $parameters): FeatureDefinition
    {
        return new FeatureDefinition([
            'featureKey' => 'audience-flag',
            'requirementType' => 'Any',
            'filters' => [[
                'name' => 'Targeting',
                'parameters' => $parameters,
            ]],
        ]);
    }

    private function manager(FeatureDefinition $definition): FeatureManager
    {
        $provider = $this->createMock(FeatureProviderInterface::class);
        $provider->method('getFeatureDefinition')->willReturn($definition);

        $secure = $this->createMock(SecureFeatureProviderInterface::class);
        $secure->method('isFeatureSecured')->willReturn(false);

        return new FeatureManager(
            $provider,
            $this->createMock(UsageStatsProviderInterface::class),
            $secure
        );
    }
}
