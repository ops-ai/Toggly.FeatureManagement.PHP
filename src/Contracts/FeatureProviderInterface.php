<?php

namespace Toggly\FeatureManagement\Contracts;

use Toggly\FeatureManagement\Models\FeatureDefinition;

/**
 * Interface for feature providers
 */
interface FeatureProviderInterface extends IFeatureExperimentProvider
{
    /**
     * Get all feature definitions
     * @return FeatureDefinition[]
     */
    public function getAllFeatureDefinitions(): array;

    /**
     * Get a feature definition by name
     */
    public function getFeatureDefinition(string $featureName): ?FeatureDefinition;

    /**
     * Assigned variant for a feature when variant mode is enabled (server-evaluated).
     *
     * @return array{name: string, configurationValue: mixed}|null
     */
    public function getVariant(string $featureKey): ?array;

    /**
     * Configuration payload for the feature's assigned variant, or null.
     *
     * @return mixed|null
     */
    public function getVariantValue(string $featureKey);
}
