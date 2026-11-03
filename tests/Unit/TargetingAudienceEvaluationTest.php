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

    public function testGroupPartialRolloutWithoutIdentityFailsClosedForThatGroup(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'Groups' => [
                        ['Name' => 'beta', 'RolloutPercentage' => 50],
                    ],
                    'DefaultRolloutPercentage' => 0,
                ],
            ],
            ['groups' => ['beta']]
        );

        $this->assertFalse($actual);
    }

    public function testDefaultPartialRolloutMissesWhenBucketAbovePercentage(): void
    {
        $featureKey = 'demo-feature';
        $identity = 'user-123';
        $bucket = FeatureManager::computePercentile($identity, $featureKey);

        $actual = $this->evaluate(
            [
                'Audience.DefaultRolloutPercentage' => max(0.0, $bucket - 0.01),
            ],
            ['identity' => $identity],
            $featureKey
        );

        $this->assertFalse($actual);
    }

    public function testFlatGroupRolloutPercentageKeyWithoutNameIsIgnored(): void
    {
        // RolloutPercentage alone cannot invent a group name.
        $actual = $this->evaluate(
            [
                'Audience.Groups:0:RolloutPercentage' => 100,
                'Audience.DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertFalse($actual);
    }

    public function testFlatGroupNameAndRolloutPercentageBinderKeys(): void
    {
        $actual = $this->evaluate(
            [
                'Audience.Groups:0:RolloutPercentage' => 100,
                'Audience.Groups:0:Name' => 'beta',
                'Audience.DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertTrue($actual);
    }

    public function testFlatGroupRolloutPercentageNonNumericIgnored(): void
    {
        $actual = $this->evaluate(
            [
                'Audience.Groups:0:Name' => 'beta',
                'Audience.Groups:0:RolloutPercentage' => 'not-a-number',
                'Audience.DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        // Non-numeric rollout → treat as full include (null rollout).
        $this->assertTrue($actual);
    }

    public function testGroupsParamAsArrayOfNamedRollouts(): void
    {
        $actual = $this->evaluate(
            [
                'Groups' => [
                    null,
                    '',
                    ['Name' => '', 'RolloutPercentage' => 100],
                    ['name' => '   '],
                    ['Name' => 'beta', 'RolloutPercentage' => '50'],
                    ['Name' => 'premium', 'rolloutPercentage' => 'not-numeric'],
                    'gamma',
                ],
                'DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'alice', 'groups' => ['premium', 'gamma']]
        );

        // premium has non-numeric rollout → full include; gamma string → full include.
        $this->assertTrue($actual);
    }

    public function testExclusionGroupMissWhenUserNotInExcludedGroup(): void
    {
        $actual = $this->evaluate(
            [
                'Audience.Exclusion.Groups:0' => 'blocked',
                'Audience.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertTrue($actual);
    }

    public function testIgnoreCaseAsIntegerFlags(): void
    {
        $caseInsensitive = $this->evaluate(
            [
                'IgnoreCase' => 1,
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        );
        $caseSensitive = $this->evaluate(
            [
                'IgnoreCase' => 0,
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        );

        $this->assertFalse($caseInsensitive);
        $this->assertTrue($caseSensitive);
    }

    public function testNestedAudienceUsersArrayAndColonExclusionKeys(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'users' => ['vip'],
                    'defaultRolloutPercentage' => 0,
                ],
                'Audience:Exclusion:Users:0' => 'vip',
            ],
            ['identity' => 'vip']
        );

        $this->assertFalse($actual);
    }

    public function testContextNullFailsClosed(): void
    {
        $definition = new FeatureDefinition([
            'featureKey' => 'null-context',
            'requirementType' => 'Any',
            'filters' => [
                new FeatureFilter([
                    'name' => 'Targeting',
                    'parameters' => [
                        'Audience.DefaultRolloutPercentage' => 100,
                    ],
                ]),
            ],
        ]);

        $this->assertFalse($this->createManager()->evaluateDefinition($definition, null));
    }

    public function testEmptyExclusionEntriesDoNotExclude(): void
    {
        $actual = $this->evaluate(
            [
                'Audience.Exclusion.Users:0' => '',
                'Audience.Exclusion.Groups:0' => '',
                'Audience.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'alice', 'groups' => ['beta']]
        );

        $this->assertTrue($actual);
    }

    public function testNestedAudienceSkipsNonArrayGroupEntriesAndWhitespaceNames(): void
    {
        $actual = $this->evaluate(
            [
                'Audience' => [
                    'Groups' => [
                        true,
                        false,
                        ['Name' => '   ', 'RolloutPercentage' => 100],
                        ['Name' => 'beta', 'RolloutPercentage' => 100],
                    ],
                    'Users' => [null, '', '  ', 'alice'],
                    'DefaultRolloutPercentage' => 0,
                ],
            ],
            ['identity' => 'other', 'groups' => ['beta']]
        );

        $this->assertTrue($actual);
    }

    public function testIgnoreCaseBoolAndUnknownValues(): void
    {
        $this->assertFalse($this->evaluate(
            [
                'IgnoreCase' => true,
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        ));
        $this->assertTrue($this->evaluate(
            [
                'IgnoreCase' => false,
                'Audience.Users:0' => 'Alice',
                'Audience.DefaultRolloutPercentage' => 0,
            ],
            ['identity' => 'Alice']
        ));
        // Unknown / non-scalar IgnoreCase → default true (match .NET/JS).
        $this->assertFalse($this->evaluate(
            [
                'IgnoreCase' => 2,
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        ));
        $this->assertFalse($this->evaluate(
            [
                'IgnoreCase' => ['nope'],
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        ));
        $this->assertFalse($this->evaluate(
            [
                'IgnoreCase' => 'True',
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        ));
        $this->assertTrue($this->evaluate(
            [
                'IgnoreCase' => 'False',
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
        ));
        $this->assertFalse($this->evaluate(
            [
                'IgnoreCase' => 'maybe',
                'Audience.Exclusion.Users:0' => 'alice',
                'Audience.DefaultRolloutPercentage' => 100,
            ],
            ['identity' => 'Alice']
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
