<?php

namespace Toggly\FeatureManagement\Telemetry;

use Toggly\FeatureManagement\Telemetry\Pb\Metrics\MetricResult;
use Toggly\FeatureManagement\Telemetry\Pb\Metrics\MetricStat;
use Toggly\FeatureManagement\Telemetry\Pb\Usage\FeatureStat;
use Toggly\FeatureManagement\Telemetry\Pb\Usage\StatResult;

/**
 * Loaded only when ext-grpc + google/protobuf are available ({@see GrpcClients::create()}).
 * Contains BaseStub subclasses only — payload conversion and Native* clients live in
 * PSR-4 source files so tests can load them without ext-grpc.
 *
 * @internal
 */

/**
 * @internal
 */
class UsageServiceClient extends \Grpc\BaseStub
{
    /**
     * @param array<string, mixed> $opts
     * @param mixed $channel
     */
    public function __construct(string $hostname, array $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param array<string, string[]> $metadata
     * @param array<string, mixed> $options
     * @return \Grpc\UnaryCall
     */
    public function SendStats(FeatureStat $argument, array $metadata = [], array $options = [])
    {
        return $this->_simpleRequest(
            '/Usage.Usage/SendStats',
            $argument,
            [StatResult::class, 'decode'],
            $metadata,
            $options
        );
    }
}

/**
 * @internal
 */
class MetricsServiceClient extends \Grpc\BaseStub
{
    /**
     * @param array<string, mixed> $opts
     * @param mixed $channel
     */
    public function __construct(string $hostname, array $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string[]> $metadata
     * @return \Grpc\UnaryCall
     */
    public function SendMetrics(MetricStat $argument, array $metadata = [], array $options = [])
    {
        return $this->_simpleRequest(
            '/Metrics.Metrics/SendMetrics',
            $argument,
            [MetricResult::class, 'decode'],
            $metadata,
            $options
        );
    }
}
