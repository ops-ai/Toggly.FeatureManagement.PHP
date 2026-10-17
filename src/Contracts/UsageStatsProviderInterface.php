<?php

namespace Toggly\FeatureManagement\Contracts;

/**
 * Service for recording feature usage statistics
 */
interface UsageStatsProviderInterface
{
    /**
     * Record a check for the feature being performed
     * @param string $featureKey Name/key of the feature
     * @param bool $allowed Decision to show feature made
     */
    public function recordCheck(string $featureKey, bool $allowed): void;

    /**
     * Record a check for the feature with context
     * @param string $featureKey Name/key of the feature
     * @param mixed $context Custom context
     * @param bool $allowed Decision to show feature made
     */
    public function recordUsageWithContext(string $featureKey, $context, bool $allowed): void;

    /**
     * Record a feature being used
     * @param string $featureKey Name/key of the feature
     * @param string $variant Variant name (defaults to {@code enabled})
     */
    public function recordUsage(string $featureKey, string $variant = 'enabled'): void;

    /**
     * Run an action inside a usage scope (attempt, duration, errors).
     *
     * @template T
     * @param callable(\Toggly\FeatureManagement\Telemetry\FeatureUsageScope): T $action
     * @return T
     */
    public function use(string $featureKey, callable $action, string $variant = 'enabled');

    /**
     * Record a feature being viewed / rendered
     * @param string $featureKey Name/key of the feature
     */
    public function recordView(string $featureKey): void;

    /**
     * Record a definition-refresh outcome served from local/cache (not a new revision).
     */
    public function recordDefinitionCacheHit(): void;

    /**
     * Record a definition-refresh that applied a new revision from the network.
     */
    public function recordDefinitionCacheMiss(): void;
}
