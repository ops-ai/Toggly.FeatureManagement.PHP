<?php

namespace Toggly\FeatureManagement\Telemetry;

use Toggly\FeatureManagement\Core\UsageStatsProvider;

/**
 * Tracks one feature usage attempt with duration and optional failure.
 * Close via {@see complete()} to record the outcome. Does not evaluate flags.
 */
final class FeatureUsageScope
{
    private ?UsageStatsProvider $provider;
    private string $featureKey;
    private string $variant;
    /** @var float */
    private $startMicrotime;
    private bool $failed = false;
    private bool $cancelled = false;
    private bool $completed = false;

    public static function noop(): self
    {
        return new self(null, '', 'enabled');
    }

    public function __construct(?UsageStatsProvider $provider, string $featureKey, string $variant = 'enabled')
    {
        $this->provider = $provider;
        $this->featureKey = $featureKey;
        $this->variant = $variant !== '' ? $variant : 'enabled';
        $this->startMicrotime = microtime(true);
    }

    public function fail(): void
    {
        $this->failed = true;
    }

    /** Attempt stays recorded; no error or duration metrics. */
    public function markCancelled(): void
    {
        $this->cancelled = true;
    }

    public function complete(): void
    {
        if ($this->completed) {
            return;
        }
        $this->completed = true;
        if ($this->cancelled || $this->provider === null || $this->featureKey === '') {
            return;
        }
        $durationMs = (int) max(0, round((microtime(true) - $this->startMicrotime) * 1000));
        $this->provider->recordUsageScopeOutcome($this->featureKey, [
            'failed' => $this->failed,
            'durationMs' => $durationMs,
        ], $this->variant);
    }
}
