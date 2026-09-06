<?php

namespace Toggly\FeatureManagement\Telemetry;

use Google\Protobuf\Timestamp;
use Toggly\FeatureManagement\Telemetry\Pb\Metrics\MetricCounterMessage;
use Toggly\FeatureManagement\Telemetry\Pb\Metrics\MetricObservationMessage;
use Toggly\FeatureManagement\Telemetry\Pb\Metrics\MetricResult;
use Toggly\FeatureManagement\Telemetry\Pb\Metrics\MetricStat;
use Toggly\FeatureManagement\Telemetry\Pb\Metrics\MetricStatMessage;
use Toggly\FeatureManagement\Telemetry\Pb\Usage\FeatureStat;
use Toggly\FeatureManagement\Telemetry\Pb\Usage\StatMessage;
use Toggly\FeatureManagement\Telemetry\Pb\Usage\StatResult;
use Toggly\FeatureManagement\Telemetry\Pb\Usage\VariantStats;

/**
 * Loaded only when ext-grpc + google/protobuf are available ({@see GrpcClients::create()}).
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

/**
 * @internal
 */
final class NativeUsageGrpcClient implements UsageGrpcClient
{
    private UsageServiceClient $stub;
    /** @var array<string, string[]> */
    private array $defaultMeta;

    /** @param array<string, string[]> $defaultMeta */
    public function __construct(UsageServiceClient $stub, array $defaultMeta)
    {
        $this->stub = $stub;
        $this->defaultMeta = $defaultMeta;
    }

    public function sendStats(array $payload, array $metadata = []): void
    {
        $meta = array_merge($this->defaultMeta, self::normalizeMetadata($metadata));
        $msg = GrpcPayloadConverter::featureStatFromPayload($payload);
        [$response, $status] = $this->stub->SendStats($msg, $meta)->wait();
        if (($status->code ?? \Grpc\STATUS_OK) !== \Grpc\STATUS_OK) {
            throw new \RuntimeException('Usage.SendStats failed: ' . ($status->details ?? 'unknown'));
        }
        unset($response);
    }

    public function close(): void
    {
    }

    /**
     * @param array<string, string> $metadata
     * @return array<string, string[]>
     */
    private static function normalizeMetadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            $out[strtolower((string) $key)] = [(string) $value];
        }

        return $out;
    }
}

/**
 * @internal
 */
final class NativeMetricsGrpcClient implements MetricsGrpcClient
{
    private MetricsServiceClient $stub;
    /** @var array<string, string[]> */
    private array $defaultMeta;

    /** @param array<string, string[]> $defaultMeta */
    public function __construct(MetricsServiceClient $stub, array $defaultMeta)
    {
        $this->stub = $stub;
        $this->defaultMeta = $defaultMeta;
    }

    public function sendMetrics(array $payload, array $metadata = []): void
    {
        $meta = array_merge($this->defaultMeta, self::normalizeMetadata($metadata));
        $msg = GrpcPayloadConverter::metricStatFromPayload($payload);
        [$response, $status] = $this->stub->SendMetrics($msg, $meta)->wait();
        if (($status->code ?? \Grpc\STATUS_OK) !== \Grpc\STATUS_OK) {
            throw new \RuntimeException('Metrics.SendMetrics failed: ' . ($status->details ?? 'unknown'));
        }
        unset($response);
    }

    public function close(): void
    {
    }

    /**
     * @param array<string, string> $metadata
     * @return array<string, string[]>
     */
    private static function normalizeMetadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            $out[strtolower((string) $key)] = [(string) $value];
        }

        return $out;
    }
}

/**
 * Converts batcher array payloads into protobuf messages.
 *
 * @internal
 */
final class GrpcPayloadConverter
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function featureStatFromPayload(array $payload): FeatureStat
    {
        $stats = [];
        foreach ($payload['stats'] ?? [] as $stat) {
            if (!is_array($stat)) {
                continue;
            }
            $variantStats = [];
            foreach ($stat['variantStats'] ?? [] as $name => $vs) {
                if (!is_array($vs)) {
                    continue;
                }
                $variantStats[(string) $name] = new VariantStats([
                    'checkCount' => (int) ($vs['checkCount'] ?? 0),
                    'requestCount' => (int) ($vs['requestCount'] ?? 0),
                    'usedCount' => (int) ($vs['usedCount'] ?? 0),
                    'viewedCount' => (int) ($vs['viewedCount'] ?? 0),
                ]);
            }
            $stats[] = new StatMessage([
                'feature' => (string) ($stat['feature'] ?? ''),
                'uniqueContextIdentifierEnabledCount' => (int) ($stat['uniqueContextIdentifierEnabledCount'] ?? 0),
                'uniqueContextIdentifierDisabledCount' => (int) ($stat['uniqueContextIdentifierDisabledCount'] ?? 0),
                'uniqueUsersUsedCount' => (int) ($stat['uniqueUsersUsedCount'] ?? 0),
                'uniqueUserHashes' => array_map('intval', $stat['uniqueUserHashes'] ?? []),
                'uniqueViewedUserHashes' => array_map('intval', $stat['uniqueViewedUserHashes'] ?? []),
                'variantStats' => $variantStats,
            ]);
        }

        $data = [
            'appKey' => (string) ($payload['appKey'] ?? ''),
            'environment' => (string) ($payload['environment'] ?? ''),
            'totalUniqueUsers' => (int) ($payload['totalUniqueUsers'] ?? 0),
            'uniqueUserHashes' => array_map('intval', $payload['uniqueUserHashes'] ?? []),
            'stats' => $stats,
        ];
        if (isset($payload['time']) && is_array($payload['time'])) {
            $data['time'] = self::timestampFromArray($payload['time']);
        }
        if (!empty($payload['instanceName'])) {
            $data['instanceName'] = (string) $payload['instanceName'];
        }
        if (!empty($payload['appVersion'])) {
            $data['appVersion'] = (string) $payload['appVersion'];
        }
        if (isset($payload['processStartTime']) && is_array($payload['processStartTime'])) {
            $data['processStartTime'] = self::timestampFromArray($payload['processStartTime']);
        }

        return new FeatureStat($data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function metricStatFromPayload(array $payload): MetricStat
    {
        $stats = [];
        foreach ($payload['stats'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [
                'metric' => (string) ($item['metric'] ?? ''),
                'variantValues' => self::floatMap($item['variantValues'] ?? []),
            ];
            if (!empty($item['feature'])) {
                $row['feature'] = (string) $item['feature'];
            }
            $stats[] = new MetricStatMessage($row);
        }

        $counters = [];
        foreach ($payload['counters'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [
                'metric' => (string) ($item['metric'] ?? ''),
                'variantValues' => self::floatMap($item['variantValues'] ?? []),
            ];
            if (!empty($item['feature'])) {
                $row['feature'] = (string) $item['feature'];
            }
            $counters[] = new MetricCounterMessage($row);
        }

        $observations = [];
        foreach ($payload['observations'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [
                'metric' => (string) ($item['metric'] ?? ''),
                'variantValues' => self::floatMap($item['variantValues'] ?? []),
            ];
            if (isset($item['time']) && is_array($item['time'])) {
                $row['time'] = self::timestampFromArray($item['time']);
            }
            if (!empty($item['feature'])) {
                $row['feature'] = (string) $item['feature'];
            }
            $observations[] = new MetricObservationMessage($row);
        }

        $data = [
            'appKey' => (string) ($payload['appKey'] ?? ''),
            'environment' => (string) ($payload['environment'] ?? ''),
            'stats' => $stats,
            'counters' => $counters,
            'observations' => $observations,
        ];
        if (isset($payload['time']) && is_array($payload['time'])) {
            $data['time'] = self::timestampFromArray($payload['time']);
        }
        if (!empty($payload['instanceName'])) {
            $data['instanceName'] = (string) $payload['instanceName'];
        }

        return new MetricStat($data);
    }

    /**
     * @param array{seconds?: int, nanos?: int} $ts
     */
    private static function timestampFromArray(array $ts): Timestamp
    {
        return new Timestamp([
            'seconds' => (int) ($ts['seconds'] ?? 0),
            'nanos' => (int) ($ts['nanos'] ?? 0),
        ]);
    }

    /**
     * @param mixed $values
     * @return array<string, float>
     */
    private static function floatMap($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $name => $value) {
            $out[(string) $name] = (float) $value;
        }

        return $out;
    }
}
