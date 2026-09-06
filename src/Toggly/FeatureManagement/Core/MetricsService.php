<?php

namespace Toggly\FeatureManagement\Core;

use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\MetricsServiceInterface;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\SdkIdentity;
use Toggly\FeatureManagement\Telemetry\GrpcClients;
use Toggly\FeatureManagement\Telemetry\MetricsGrpcClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for collecting and sending custom metrics.
 *
 * Prefers native gRPC {@code Metrics.SendMetrics} when available; otherwise uses
 * the gateway-accepted HTTPS JSON path ({@code api/metrics}). Wire payloads use
 * {@code variantValues}. Periodic flush is host-driven (Laravel schedule /
 * WP-Cron); {@see flush()} provides best-effort shutdown flush.
 */
class MetricsService implements MetricsServiceInterface
{
    private TogglySettings $settings;
    private TogglyHttpClient $httpClient;
    private FeatureProviderInterface $featureProvider;
    private MetricsRegistryService $metricsRegistry;
    private LoggerInterface $logger;
    private ?MetricsGrpcClient $grpcClient;

    /**
     * @var array<string, array<string, float>> Measurements by metric key, variant-keyed
     */
    private array $measurements = [];

    /**
     * @var array<array{time: int, metricKey: string, featureKey: string|null, variant: string, value: float}>
     */
    private array $observations = [];

    /**
     * @var array<string, array<string, float>> Counters by metric key, variant-keyed
     */
    private array $counters = [];

    private bool $sendInProgress = false;
    private bool $shutdownRegistered = false;
    private ?int $lastSend = null;

    public function __construct(
        TogglySettings $settings,
        TogglyHttpClient $httpClient,
        FeatureProviderInterface $featureProvider,
        MetricsRegistryService $metricsRegistry,
        ?LoggerInterface $logger = null,
        ?MetricsGrpcClient $grpcClient = null
    ) {
        $this->settings = $settings;
        $this->httpClient = $httpClient;
        $this->featureProvider = $featureProvider;
        $this->metricsRegistry = $metricsRegistry;
        $this->logger = $logger ?? new NullLogger();

        if ($grpcClient !== null) {
            $this->grpcClient = $grpcClient;
        } else {
            $clients = GrpcClients::create(
                $this->resolveMetricsBaseUrl(),
                SdkIdentity::userAgent(),
                $this->logger
            );
            $this->grpcClient = $clients !== null ? $clients->metrics() : null;
            if ($clients === null && !GrpcClients::isAvailable()) {
                $this->logger->debug(
                    'Metrics gRPC unavailable (need ext-grpc + google/protobuf); using HTTPS JSON fallback'
                );
            }
        }

        $this->registerShutdownFlush();
    }

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (\Throwable $e) {
            // Best-effort
        }
    }

    /**
     * @inheritDoc
     */
    public function measure(string $metricKey, float $value): void
    {
        $this->incrementMeasurement($metricKey, null, $value, true);

        $features = $this->featureProvider->getFeaturesForMetric($metricKey);
        if ($features !== null) {
            foreach ($features as $feature) {
                $this->incrementMeasurement($metricKey, $feature, $value, true);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function measureWithContext(string $metricKey, $context, float $value): void
    {
        $this->measure($metricKey, $value);
    }

    /**
     * @inheritDoc
     */
    public function observe(string $metricKey, float $value): void
    {
        $date = time();
        $this->storeObservation($date, $metricKey, null, $value, true);

        $features = $this->featureProvider->getFeaturesForMetric($metricKey);
        if ($features !== null) {
            foreach ($features as $feature) {
                $this->storeObservation($date, $metricKey, $feature, $value, true);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function observeWithContext(string $metricKey, $context, float $value): void
    {
        $this->observe($metricKey, $value);
    }

    /**
     * @inheritDoc
     */
    public function incrementCounter(string $metricKey, float $value = 1.0): void
    {
        $this->incrementMetricCounter($metricKey, null, $value, true);

        $features = $this->featureProvider->getFeaturesForMetric($metricKey);
        if ($features !== null) {
            foreach ($features as $feature) {
                $this->incrementMetricCounter($metricKey, $feature, $value, true);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function incrementCounterWithContext(string $metricKey, $context, float $value = 1.0): void
    {
        $this->incrementCounter($metricKey, $value);
    }

    /**
     * Best-effort flush (alias of {@see sendMetrics()}).
     */
    public function flush(): void
    {
        $this->sendMetrics();
    }

    /**
     * Build current MetricStat-shaped payload without clearing (tests).
     *
     * @return array<string, mixed>|null
     */
    public function peekPayload(): ?array
    {
        return $this->buildPayload(false);
    }

    /**
     * Send metrics to Toggly (single-flight).
     */
    public function sendMetrics(): void
    {
        if ($this->sendInProgress) {
            $this->logger->debug('Send metrics already in progress, skipping');
            return;
        }

        $registryMeasurements = $this->metricsRegistry->getMeasurementValues();
        foreach ($registryMeasurements as $key => $value) {
            $this->incrementMeasurement($key, null, $value, true);
        }

        $registryCounters = $this->metricsRegistry->getCounterValues();
        foreach ($registryCounters as $key => $value) {
            $this->incrementMetricCounter($key, null, $value, true);
        }

        $registryObservations = $this->metricsRegistry->getObservationValues();
        foreach ($registryObservations as $key => $data) {
            $this->storeObservation($data[0], $key, null, $data[1], true);
        }

        $payload = $this->buildPayload(true);
        if ($payload === null) {
            $this->logger->debug('No metrics to send');
            return;
        }

        $this->sendInProgress = true;

        try {
            if ($this->grpcClient !== null) {
                $this->grpcClient->sendMetrics($payload);
            } else {
                $this->httpClient->post('api/metrics', $this->toHttpJsonPayload($payload));
            }
            $this->lastSend = time();
            $this->logger->debug('Metrics sent successfully', [
                'transport' => $this->grpcClient !== null ? 'grpc' : 'http',
            ]);
        } catch (\Throwable $e) {
            $this->restoreFromPayload($payload);
            $this->logger->error('Error sending metrics to Toggly', ['error' => $e->getMessage()]);
        } finally {
            $this->sendInProgress = false;
        }
    }

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            try {
                $this->flush();
            } catch (\Throwable $e) {
                // ignore
            }
        });
    }

    private function resolveMetricsBaseUrl(): string
    {
        $base = $this->settings->baseUrl;
        if ($base !== null && $base !== '') {
            return $base;
        }

        return GrpcClients::DEFAULT_METRICS_BASE_URL;
    }

    private function incrementMeasurement(string $metricKey, ?string $featureKey, float $value, bool $enabled): void
    {
        $variant = $enabled ? 'enabled' : 'disabled';
        $this->incrementMeasurementVariant($metricKey, $featureKey, $value, $variant);
    }

    private function incrementMeasurementVariant(string $metricKey, ?string $featureKey, float $value, string $variant): void
    {
        $key = $featureKey !== null ? "{$metricKey}:{$featureKey}" : $metricKey;
        if (!isset($this->measurements[$key])) {
            $this->measurements[$key] = [];
        }
        if (!isset($this->measurements[$key][$variant])) {
            $this->measurements[$key][$variant] = 0.0;
        }
        $this->measurements[$key][$variant] += $value;
    }

    private function storeObservation(int $date, string $metricKey, ?string $featureKey, float $value, bool $enabled): void
    {
        $variant = $enabled ? 'enabled' : 'disabled';
        $this->observations[] = [
            'time' => $date,
            'metricKey' => $metricKey,
            'featureKey' => $featureKey,
            'variant' => $variant,
            'value' => $value,
        ];
    }

    private function incrementMetricCounter(string $metricKey, ?string $featureKey, float $value, bool $enabled): void
    {
        $variant = $enabled ? 'enabled' : 'disabled';
        $key = $featureKey !== null ? "{$metricKey}:{$featureKey}" : $metricKey;
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = [];
        }
        if (!isset($this->counters[$key][$variant])) {
            $this->counters[$key][$variant] = 0.0;
        }
        $this->counters[$key][$variant] += $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPayload(bool $reset): ?array
    {
        if (empty($this->measurements) && empty($this->counters) && empty($this->observations)) {
            return null;
        }

        $measurementsToSend = $this->measurements;
        $countersToSend = $this->counters;
        $observationsToSend = $this->observations;

        $payload = [
            'appKey' => $this->settings->appKey,
            'environment' => $this->settings->environment,
            'time' => GrpcClients::toProtobufTimestamp(),
            'instanceName' => $this->settings->instanceName ?? gethostname(),
            'stats' => [],
            'counters' => [],
            'observations' => [],
        ];

        foreach ($measurementsToSend as $key => $variantValues) {
            [$metricKey, $featureKey] = $this->parseKey($key);
            $stat = [
                'metric' => $metricKey,
                'variantValues' => $variantValues,
            ];
            if ($featureKey !== null) {
                $stat['feature'] = $featureKey;
            }
            $payload['stats'][] = $stat;
        }

        foreach ($countersToSend as $key => $variantValues) {
            [$metricKey, $featureKey] = $this->parseKey($key);
            $counter = [
                'metric' => $metricKey,
                'variantValues' => $variantValues,
            ];
            if ($featureKey !== null) {
                $counter['feature'] = $featureKey;
            }
            $payload['counters'][] = $counter;
        }

        $observationGroups = [];
        foreach ($observationsToSend as $obs) {
            $groupKey = $obs['time'] . ':' . $obs['metricKey'] . ':' . ($obs['featureKey'] ?? '');
            if (!isset($observationGroups[$groupKey])) {
                $observationGroups[$groupKey] = [
                    'metric' => $obs['metricKey'],
                    'time' => GrpcClients::toProtobufTimestamp($obs['time']),
                    'variantValues' => [],
                ];
                if ($obs['featureKey'] !== null) {
                    $observationGroups[$groupKey]['feature'] = $obs['featureKey'];
                }
            }
            $observationGroups[$groupKey]['variantValues'][$obs['variant']] = $obs['value'];
        }
        $payload['observations'] = array_values($observationGroups);

        if ($reset) {
            $this->measurements = [];
            $this->counters = [];
            $this->observations = [];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function toHttpJsonPayload(array $payload): array
    {
        $out = $payload;
        $out['time'] = date('c', (int) (($payload['time']['seconds'] ?? time())));
        foreach ($out['observations'] as $i => $obs) {
            if (isset($obs['time']['seconds'])) {
                $out['observations'][$i]['time'] = date('c', (int) $obs['time']['seconds']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function restoreFromPayload(array $payload): void
    {
        foreach ($payload['stats'] ?? [] as $stat) {
            if (!is_array($stat)) {
                continue;
            }
            $key = isset($stat['feature']) ? $stat['metric'] . ':' . $stat['feature'] : $stat['metric'];
            foreach ($stat['variantValues'] ?? [] as $variant => $value) {
                if (!isset($this->measurements[$key][$variant])) {
                    $this->measurements[$key][$variant] = 0.0;
                }
                $this->measurements[$key][$variant] += (float) $value;
            }
        }
        foreach ($payload['counters'] ?? [] as $counter) {
            if (!is_array($counter)) {
                continue;
            }
            $key = isset($counter['feature']) ? $counter['metric'] . ':' . $counter['feature'] : $counter['metric'];
            foreach ($counter['variantValues'] ?? [] as $variant => $value) {
                if (!isset($this->counters[$key][$variant])) {
                    $this->counters[$key][$variant] = 0.0;
                }
                $this->counters[$key][$variant] += (float) $value;
            }
        }
        foreach ($payload['observations'] ?? [] as $obs) {
            if (!is_array($obs)) {
                continue;
            }
            $seconds = is_array($obs['time'] ?? null)
                ? (int) ($obs['time']['seconds'] ?? time())
                : time();
            foreach ($obs['variantValues'] ?? [] as $variant => $value) {
                $this->observations[] = [
                    'time' => $seconds,
                    'metricKey' => (string) ($obs['metric'] ?? ''),
                    'featureKey' => $obs['feature'] ?? null,
                    'variant' => (string) $variant,
                    'value' => (float) $value,
                ];
            }
        }
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function parseKey(string $key): array
    {
        $parts = explode(':', $key, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return [$key, null];
    }
}
