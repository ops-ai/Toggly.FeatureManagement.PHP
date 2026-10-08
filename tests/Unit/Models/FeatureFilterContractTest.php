<?php

namespace Toggly\FeatureManagement\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\FeatureFilter;

final class FeatureFilterContractTest extends TestCase
{
    public function testDefinitionSerializationPreservesPopulatedEmptyAndOmittedFilterParameters(): void
    {
        $definition = new FeatureDefinition([
            'featureKey' => 'checkout',
            'filters' => [
                ['name' => 'Percentage', 'parameters' => ['Value' => '25', 'Audience' => 'beta']],
                new FeatureFilter(['name' => 'AlwaysOn', 'parameters' => []]),
                ['name' => 'AlwaysOff'],
            ],
        ]);

        $serialized = $definition->toArray();

        $this->assertSame([
            ['name' => 'Percentage', 'parameters' => ['Value' => '25', 'Audience' => 'beta']],
            ['name' => 'AlwaysOn', 'parameters' => []],
            ['name' => 'AlwaysOff'],
        ], $serialized['filters']);
    }

    public function testEqualityRequiresTheSameNameAndParameterState(): void
    {
        $percentage = new FeatureFilter(['name' => 'Percentage', 'parameters' => ['Value' => '25']]);

        $this->assertTrue($percentage->equals(new FeatureFilter([
            'name' => 'Percentage',
            'parameters' => ['Value' => '25'],
        ])));
        $this->assertFalse($percentage->equals(null));
        $this->assertFalse($percentage->equals(new FeatureFilter([
            'name' => 'Targeting',
            'parameters' => ['Value' => '25'],
        ])));
        $this->assertFalse($percentage->equals(new FeatureFilter([
            'name' => 'Percentage',
            'parameters' => ['Value' => '50'],
        ])));
        $this->assertFalse($percentage->equals(new FeatureFilter(['name' => 'Percentage'])));
        $this->assertTrue(
            (new FeatureFilter(['name' => 'AlwaysOn']))->equals(new FeatureFilter(['name' => 'AlwaysOn']))
        );
    }
}
