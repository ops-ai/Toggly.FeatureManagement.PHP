<?php

namespace Toggly\FeatureManagement\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\SecureFeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\FeatureFilter;

/**
 * Targeting filter evaluation against real FeatureManager definitions.
 *
 * Covers exclusions, nested Audience JSON (Toggly API / MF shape), flat-key
 * backward compatibility, and malformed / missing Audience.
 */
class TargetingAudienceEvaluationTest extends TestCase
{
    public function testExclusionUserWinsOverDefaultRollout100(): void
    {
        $actual = $this->evaluate(
            [
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'alice']
        );

        $this->assertFalse($actual);
    }

    public function testExclusionGroupWinsOverIncludedUser(): void
    {
        $actual = $this->evaluate(
            [
                'Audience.Exclusion.Groups:0' => 'blocked',
                'Audience.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'alice', 'groups' => ['blocked']]
        );

        $this->assertFalse($actual);
    }

    public function testNestedAudienceExclusionUsers(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'Exclusion' => [
                        'Users' => ['alice'],
                        'Groups' => [],
                    ],
                    'Users' => ['alice'],
                    'DefaultRolloutPercentage' => 100,
                ],
            ],
            ['identity' => 'alice']
        );

        $this->assertFalse($actual);
    }

    public function testNestedAudienceExclusionGroups(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'Exclusion' => [
                        'Users' => [],
                        'Groups' => ['beta'],
                    ],
                    'DefaultRolloutPercentage' => 100,
                ],
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertFalse($actual);
    }

    public function testNestedAudienceIncludedUsers(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'Users' => ['alice', 'bob'],
                    'DefaultRolloutPercentage' => 0,
                ],
            ],
            ['identity' => 'alice']
        );

        $this->assertTrue($actual);
    }

    public function testNestedAudienceGroupRollout100Includes(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'Groups' => [
                        ['Name' => 'beta', 'RolloutPercentage' => 100],
                    ],
                    'DefaultRolloutPercentage' => 0,
                ],
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertTrue($actual);
    }

    public function testNestedAudienceGroupRollout0DoesNotInclude(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'Groups' => [
                        ['Name' => 'beta', 'RolloutPercentage' => 0],
                    ],
                    'DefaultRolloutPercentage' => 0,
                ],
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertFalse($actual);
    }

    public function testNestedAudienceGroupPartialRolloutUsesExistingBucket(): void
    {
        $featureKey = 'demo-feature';
        $identity = 'user-123';
        $bucket = FeatureManager::computePercentile($identity, $featureKey);

        $above = $this->evaluate(
            [
                'Audience' => [
                    'Groups' => [
                        ['Name' => 'beta', 'RolloutPercentage' => $bucket + 0.01],
                    ],
                    'DefaultRolloutPercentage' => 0,
                ],
            ],
            ['identity' => $identity, 'groups' => ['beta']],
            $featureKey
        );
        $below = $this->evaluate(
            [
                'Audience' => [
                    'Groups' => [
                        ['Name' => 'beta', 'RolloutPercentage' => max(0.0, $bucket - 0.01)],
                    ],
                    'DefaultRolloutPercentage' => 0,
                ],
            ],
            ['identity' => $identity, 'groups' => ['beta']],
            $featureKey
        );

        $this->assertTrue($above);
        $this->assertFalse($below);
    }

    public function testNestedAudienceDefaultRollout(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'DefaultRolloutPercentage' => 100,
                ],
            ],
            []
        );

        $this->assertTrue($actual);
    }

    public function testFlatUsersGroupsAndDefaultRolloutBackwardCompatible(): void
    {
        $this->assertTrue($this->evaluate(
            [
                'Audience.DefaultRolloutPercentage' => 0,
                'Audience.Users:0' => 'alice',
            ],
            ['identity' => 'alice']
        ));
        $this->assertFalse($this->evaluate(
            [
                'Audience.DefaultRolloutPercentage' => 0,
                'Audience.Users:0' => 'alice',
            ],
            ['identity' => 'bob']
        ));
        $this->assertTrue($this->evaluate(
            [
                'Audience.DefaultRolloutPercentage' => 0,
                'Audience.Groups:0' => 'beta',
            ],
            ['identity' => 'u', 'groups' => ['beta']]
        ));
        $this->assertTrue($this->evaluate(
            [
                'users' => 'alice,bob',
                'DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'bob']
        ));
        $this->assertTrue($this->evaluate(
            [
                'groups' => 'beta,premium',
                'DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'u', 'groups' => ['premium']]
        ));
    }

    public function testColonBoundAudienceGroupsNameFallback(): void
    {
        $actual = $this->evaluate(
            [
                'Audience:Groups:0:Name' => 'beta',
                'Audience:DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertTrue($actual);
    }

    public function testIgnoreCaseDefaultMatchesExclusionCaseInsensitively(): void
    {
        $actual = $this->evaluate(
            [
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        );

        $this->assertFalse($actual);
    }

    public function testIgnoreCaseFalseKeepsExclusionCaseSensitive(): void
    {
        $actual = $this->evaluate(
            [
                'IgnoreCase' => 'false',
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        );

        $this->assertTrue($actual);
    }

    public function testMalformedAudienceIgnoredFallsBackToFlatKeys(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => 'not-an-object',
                'Audience.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'alice']
        );

        $this->assertTrue($actual);
    }

    public function testMissingAudienceWithNoRulesFailsClosed(): void
    {
        $actual = $this->evaluate(
            [],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertFalse($actual);
    }

    public function testNestedAudienceWithEmptyGroupsAndNullUsersFailsClosed(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'Users' => null,
                    'Groups' => [
                        ['Name' => null, 'RolloutPercentage' => 100],
                        '   ',
                        42,
                    ],
                    'Exclusion' => 'bad',
                    'DefaultRolloutPercentage' => 0,
                ],
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertFalse($actual);
    }

    public function testApiShapedFlagDefinitionEvaluatesThroughFeatureManager(): void
    {
        // Shape mirrors a Toggly definitions-signed Targeting filter payload.
        $definition = new FeatureDefinition([
            'featureKey' => 'api-targeting',
            'requirementType' => 'Any',
            'filters' => [
                new FeatureFilter([
                    'name' => 'Microsoft.Targeting',
                    'parameters' => [
                        'Audience' => [
                            'Exclusion' => [
                                'Users' => ['blocked-user'],
                                'Groups' => ['blocked-group'],
                            ],
                            'Users' => ['vip'],
                            'Groups' => [
                                ['Name' => 'beta', 'RolloutPercentage' => 100],
                            ],
                            'DefaultRolloutPercentage' => 0,
                        ],
                    ],
                ]),
            ],
        ]);

        $manager = $this->createManager();

        $this->assertFalse($manager->evaluateDefinition(
            $definition,
            ['identity' => 'blocked-user', 'groups' => ['beta']]
        ));
        $this->assertFalse($manager->evaluateDefinition(
            $definition,
            ['identity' => 'vip', 'groups' => ['blocked-group']]
        ));
        $this->assertTrue($manager->evaluateDefinition(
            $definition,
            ['identity' => 'vip']
        ));
        $this->assertTrue($manager->evaluateDefinition(
            $definition,
            ['identity' => 'other', 'groups' => ['beta']]
        ));
        $this->assertFalse($manager->evaluateDefinition(
            $definition,
            ['identity' => 'other', 'groups' => ['standard']]
        ));
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    private function evaluate(array $parameters, array $context, string $featureKey = 'parity-targeting'): bool
    {
        $definition = new FeatureDefinition([
            'featureKey' => $featureKey,
            'requirementType' => 'Any',
            'filters' => [
                new FeatureFilter([
                    'name' => 'Targeting',
                    'parameters' => $parameters,
                ]),
            ],
        ]);

        return $this->createManager()->evaluateDefinition($definition, $context);
    }

    private function createManager(): FeatureManager
    {
        return new FeatureManager(
            $this->createStub(FeatureProviderInterface::class),
            $this->createStub(UsageStatsProviderInterface::class),
            $this->createStub(SecureFeatureProviderInterface::class)
        );
    }
}
