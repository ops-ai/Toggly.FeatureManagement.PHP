<?php

namespace Toggly\FeatureManagement\Telemetry;

/**
 * Minimal usage transport used by UsageStatsProvider.
 */
interface UsageGrpcClient
{
    /**
     * @param array<string, mixed> $payload FeatureStat-shaped array
     * @param array<string, string> $metadata Extra metadata (merged with default UA)
     */
    public function sendStats(array $payload, array $metadata = []): void;

    public function close(): void;
}
