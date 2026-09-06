<?php

namespace Toggly\FeatureManagement\Telemetry;

/**
 * Minimal metrics transport used by MetricsService.
 */
interface MetricsGrpcClient
{
    /**
     * @param array<string, mixed> $payload MetricStat-shaped array
     * @param array<string, string> $metadata Extra metadata (merged with default UA)
     */
    public function sendMetrics(array $payload, array $metadata = []): void;

    public function close(): void;
}
