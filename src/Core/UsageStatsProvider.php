<?php

namespace Toggly\FeatureManagement\Core;

use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureContextProviderInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\SdkIdentity;
use Toggly\FeatureManagement\Telemetry\FeatureUsageScope;
use Toggly\FeatureManagement\Telemetry\GrpcClients;
use Toggly\FeatureManagement\Telemetry\IdentityHasher;
use Toggly\FeatureManagement\Telemetry\UsageGrpcClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Collects and sends feature usage statistics to Toggly.
 *
 * Prefers native gRPC {@code Usage.SendStats} when ext-grpc + google/protobuf are
 * available; otherwise soft-fails to the gateway-accepted HTTPS JSON path
 * ({@code api/usage/stats}). Wire payloads use {@code variantStats} (not legacy
 * enabled/disabled scalars). Periodic flush is host-driven (Laravel schedule /
 * WP-Cron); {@see flush()} / destructor provide best-effort shutdown flush.
 */
class UsageStatsProvider implements UsageStatsProviderInterface
{
    private TogglySettings $settings;
    private TogglyHttpClient $httpClient;
    private ?FeatureContextProviderInterface $contextProvider;
    private LoggerInterface $logger;
    private ?UsageGrpcClient $grpcClient;
    private ?GrpcClients $ownedGrpcClients = null;
    private int $processStartTime;

    private const MAX_UNIQUE_USER_HASHES_PER_FEATURE = 10000;
    private const MAX_APPLICATION_UNIQUE_USER_HASHES = 10000;

    /** @var array<string, array<string, array{checkCount: int, requestCount: int, usedCount: int, viewedCount: int, errorCount: int, durationSumMs: int, errorDurationSumMs: int}>> */
    private array $variantStats = [];

    /** @var array<string, array<int, true>> */
    private array $uniqueUsageEnabled = [];

    /** @var array<string, array<int, true>> */
    private array $uniqueUsageDisabled = [];

    /** @var array<string, array<int, true>> */
    private array $uniqueUsageUsed = [];

    /** @var array<string, array<int, true>> */
    private array $uniqueUserHashes = [];

    /** @var array<string, array<int, true>> */
    private array $uniqueViewedUserHashes = [];

    /** @var array<int, true> */
    private array $applicationUniqueUserHashes = [];

    private int $definitionCacheHits = 0;
    private int $definitionCacheMisses = 0;

    private bool $sendInProgress = false;
    private bool $shutdownRegistered = false;
    private ?int $lastSend = null;

    public function __construct(
        TogglySettings $settings,
        TogglyHttpClient $httpClient,
        ?FeatureContextProviderInterface $contextProvider = null,
        ?LoggerInterface $logger = null,
        ?UsageGrpcClient $grpcClient = null
    ) {
        $this->settings = $settings;
        $this->httpClient = $httpClient;
        $this->contextProvider = $contextProvider;
        $this->logger = $logger ?? new NullLogger();
        $this->processStartTime = time();

        if ($grpcClient !== null) {
            $this->grpcClient = $grpcClient;
        } else {
            $clients = GrpcClients::create(
                $this->resolveMetricsBaseUrl(),
                SdkIdentity::userAgent(),
                $this->logger
            );
            $this->ownedGrpcClients = $clients;
            $this->grpcClient = $clients !== null ? $clients->usage() : null;
            if ($clients === null && !GrpcClients::isAvailable()) {
                $this->logger->debug(
                    'Usage gRPC unavailable (need ext-grpc + google/protobuf); using HTTPS JSON fallback'
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
            // Best-effort shutdown flush
        }
        if ($this->ownedGrpcClients !== null) {
            try {
                $this->ownedGrpcClients->close();
            } catch (\Throwable $e) {
                // Best-effort
            }
            $this->ownedGrpcClients = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function recordCheck(string $featureKey, bool $allowed): void
    {
        $variant = $allowed ? 'enabled' : 'disabled';
        $uniqueRequest = false;

        if ($this->contextProvider !== null) {
            $accessed = $this->contextProvider->accessedInRequest($featureKey);
            if (!$accessed) {
                $uniqueRequest = true;
            }
        }

        $this->bumpVariant($featureKey, $variant, 'checkCount');
        if ($uniqueRequest) {
            $this->bumpVariant($featureKey, $variant, 'requestCount');
        }

        if ($this->contextProvider !== null) {
            $identifier = $this->contextProvider->getContextIdentifier();
            if ($identifier !== null && $identifier !== '') {
                $hash = IdentityHasher::hashIdentity($identifier);
                if ($allowed) {
                    $this->uniqueUsageEnabled[$featureKey][$hash] = true;
                } else {
                    $this->uniqueUsageDisabled[$featureKey][$hash] = true;
                }
                $this->recordApplicationUniqueUserId($identifier);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function recordUsageWithContext(string $featureKey, $context, bool $allowed): void
    {
        $this->recordCheck($featureKey, $allowed);
    }

    /**
     * @inheritDoc
     */
    public function recordUsage(string $featureKey, string $variant = 'enabled'): void
    {
        $this->bumpVariant($featureKey, $variant !== '' ? $variant : 'enabled', 'usedCount');

        if ($this->contextProvider !== null) {
            $identifier = $this->contextProvider->getContextIdentifier();
            if ($identifier !== null && $identifier !== '') {
                $hash = IdentityHasher::hashIdentity($identifier);
                $this->uniqueUsageUsed[$featureKey][$hash] = true;
                $this->recordUniqueUserId($featureKey, $identifier);
                $this->recordApplicationUniqueUserId($identifier);
            }
        }
    }

    /**
     * @param array{failed?: bool, cancelled?: bool, durationMs?: int} $outcome
     */
    public function recordUsageScopeOutcome(string $featureKey, array $outcome, string $variant = 'enabled'): void
    {
        if (($outcome['cancelled'] ?? false) === true) {
            return;
        }
        $variant = $variant !== '' ? $variant : 'enabled';
        if (($outcome['failed'] ?? false) === true) {
            $this->bumpVariant($featureKey, $variant, 'errorCount');
            if (isset($outcome['durationMs']) && (int) $outcome['durationMs'] > 0) {
                $this->addDurationMs($featureKey, $variant, (int) $outcome['durationMs'], true);
            }
        } elseif (isset($outcome['durationMs']) && (int) $outcome['durationMs'] > 0) {
            $this->addDurationMs($featureKey, $variant, (int) $outcome['durationMs'], false);
        }
    }

    public function beginUsage(string $featureKey, string $variant = 'enabled'): FeatureUsageScope
    {
        if ($featureKey === '') {
            return FeatureUsageScope::noop();
        }
        $variant = $variant !== '' ? $variant : 'enabled';
        $this->recordUsage($featureKey, $variant);

        return new FeatureUsageScope($this, $featureKey, $variant);
    }

    /**
     * @template T
     * @param callable(FeatureUsageScope): T $action
     * @return T
     */
    public function use(string $featureKey, callable $action, string $variant = 'enabled')
    {
        $scope = $this->beginUsage($featureKey, $variant);
        try {
            return $action($scope);
        } catch (\Throwable $e) {
            if ($this->isUsageCancellation($e)) {
                $scope->markCancelled();
            } else {
                $scope->fail();
            }
            throw $e;
        } finally {
            $scope->complete();
        }
    }

    /**
     * Record a feature being viewed / rendered.
     */
    public function recordView(string $featureKey): void
    {
        $this->bumpVariant($featureKey, 'enabled', 'viewedCount');

        if ($this->contextProvider !== null) {
            $identifier = $this->contextProvider->getContextIdentifier();
            if ($identifier !== null && $identifier !== '') {
                $this->recordUniqueViewedUserId($featureKey, $identifier);
                $this->recordApplicationUniqueUserId($identifier);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function recordDefinitionCacheHit(): void
    {
        $this->definitionCacheHits++;
    }

    /**
     * @inheritDoc
     */
    public function recordDefinitionCacheMiss(): void
    {
        $this->definitionCacheMisses++;
    }

    /**
     * Best-effort flush (alias of {@see sendStats()}).
     */
    public function flush(): void
    {
        $this->sendStats();
    }

    /**
     * Build the current FeatureStat-shaped payload without clearing (tests).
     *
     * @return array<string, mixed>|null
     */
    public function peekPayload(): ?array
    {
        return $this->buildPayload(false);
    }

    /**
     * Send statistics to Toggly (single-flight).
     */
    public function sendStats(): void
    {
        if ($this->sendInProgress) {
            $this->logger->debug('Send stats already in progress, skipping');
            return;
        }

        // Snapshot unique-usage hash sets before buildPayload clears them.
        // Wire payloads only carry counts for these maps, so restore must use
        // in-memory snapshots (same idea as .NET ConcurrentHashSet clones).
        $uniqueUsageEnabledSnapshot = $this->uniqueUsageEnabled;
        $uniqueUsageDisabledSnapshot = $this->uniqueUsageDisabled;
        $uniqueUsageUsedSnapshot = $this->uniqueUsageUsed;

        $payload = $this->buildPayload(true);
        if ($payload === null) {
            $this->logger->debug('No stats to send');
            return;
        }

        $this->sendInProgress = true;

        try {
            if ($this->grpcClient !== null) {
                $this->grpcClient->sendStats($payload);
            } else {
                $this->httpClientForUsage()->post('api/usage/stats', $this->toHttpJsonPayload($payload), 1);
            }
            $this->lastSend = time();
            $this->logger->debug('Statistics sent successfully', [
                'transport' => $this->grpcClient !== null ? 'grpc' : 'http',
            ]);
        } catch (\Throwable $e) {
            $this->restoreFromPayload($payload);
            $this->restoreUniqueUsageMaps(
                $uniqueUsageEnabledSnapshot,
                $uniqueUsageDisabledSnapshot,
                $uniqueUsageUsedSnapshot
            );
            $this->logger->error('Error sending stats to Toggly', ['error' => $e->getMessage()]);
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
        return GrpcClients::resolveHttpMetricsBaseUrl($this->settings->baseUrl);
    }

    private function httpClientForUsage(): TogglyHttpClient
    {
        $current = $this->httpClient->getBaseUrl();
        if (GrpcClients::isDefinitionsCdn($current) || GrpcClients::isProductApiHost($current)) {
            return $this->httpClient->withBaseUrl($this->resolveMetricsBaseUrl());
        }

        return $this->httpClient;
    }

    /**
     * @param 'checkCount'|'requestCount'|'usedCount'|'viewedCount'|'errorCount' $field
     */
    private function bumpVariant(string $featureKey, string $variant, string $field): void
    {
        if (!isset($this->variantStats[$featureKey][$variant])) {
            $this->variantStats[$featureKey][$variant] = $this->emptyVariantStats();
        }
        $this->variantStats[$featureKey][$variant][$field]++;
    }

    private function addDurationMs(string $featureKey, string $variant, int $durationMs, bool $failed): void
    {
        if ($durationMs <= 0) {
            return;
        }
        if (!isset($this->variantStats[$featureKey][$variant])) {
            $this->variantStats[$featureKey][$variant] = $this->emptyVariantStats();
        }
        $field = $failed ? 'errorDurationSumMs' : 'durationSumMs';
        $this->variantStats[$featureKey][$variant][$field] += $durationMs;
    }

    /**
     * @return array{checkCount: int, requestCount: int, usedCount: int, viewedCount: int, errorCount: int, durationSumMs: int, errorDurationSumMs: int}
     */
    private function emptyVariantStats(): array
    {
        return [
            'checkCount' => 0,
            'requestCount' => 0,
            'usedCount' => 0,
            'viewedCount' => 0,
            'errorCount' => 0,
            'durationSumMs' => 0,
            'errorDurationSumMs' => 0,
        ];
    }

    private function isUsageCancellation(\Throwable $error): bool
    {
        $name = (new \ReflectionClass($error))->getShortName();

        return $name === 'AbortException' || $name === 'CanceledException';
    }

    private function recordUniqueUserId(string $featureKey, string $userId): void
    {
        if ($featureKey === '' || $userId === '') {
            return;
        }
        $hash = IdentityHasher::hashIdentity($userId);
        if (!isset($this->uniqueUserHashes[$featureKey])) {
            $this->uniqueUserHashes[$featureKey] = [];
        }
        if (
            !isset($this->uniqueUserHashes[$featureKey][$hash])
            && count($this->uniqueUserHashes[$featureKey]) >= self::MAX_UNIQUE_USER_HASHES_PER_FEATURE
        ) {
            $this->logger->warning('Unique user hash limit reached for feature', ['feature' => $featureKey]);
            return;
        }
        $this->uniqueUserHashes[$featureKey][$hash] = true;
    }

    private function recordUniqueViewedUserId(string $featureKey, string $userId): void
    {
        if ($featureKey === '' || $userId === '') {
            return;
        }
        $hash = IdentityHasher::hashIdentity($userId);
        if (!isset($this->uniqueViewedUserHashes[$featureKey])) {
            $this->uniqueViewedUserHashes[$featureKey] = [];
        }
        if (
            !isset($this->uniqueViewedUserHashes[$featureKey][$hash])
            && count($this->uniqueViewedUserHashes[$featureKey]) >= self::MAX_UNIQUE_USER_HASHES_PER_FEATURE
        ) {
            $this->logger->warning('Unique viewed user hash limit reached for feature', ['feature' => $featureKey]);
            return;
        }
        $this->uniqueViewedUserHashes[$featureKey][$hash] = true;
    }

    private function recordApplicationUniqueUserId(string $userId): void
    {
        if ($userId === '') {
            return;
        }
        $hash = IdentityHasher::hashIdentity($userId);
        if (
            !isset($this->applicationUniqueUserHashes[$hash])
            && count($this->applicationUniqueUserHashes) >= self::MAX_APPLICATION_UNIQUE_USER_HASHES
        ) {
            $this->logger->warning('Application-level unique user hash limit reached');
            return;
        }
        $this->applicationUniqueUserHashes[$hash] = true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPayload(bool $reset): ?array
    {
        if (
            empty($this->variantStats)
            && empty($this->uniqueUserHashes)
            && empty($this->uniqueViewedUserHashes)
            && empty($this->applicationUniqueUserHashes)
            && $this->definitionCacheHits === 0
            && $this->definitionCacheMisses === 0
        ) {
            return null;
        }

        $featureKeys = array_unique(array_merge(
            array_keys($this->variantStats),
            array_keys($this->uniqueUsageEnabled),
            array_keys($this->uniqueUsageDisabled),
            array_keys($this->uniqueUsageUsed),
            array_keys($this->uniqueUserHashes),
            array_keys($this->uniqueViewedUserHashes)
        ));

        $stats = [];
        foreach ($featureKeys as $featureKey) {
            $variantWire = [];
            foreach ($this->variantStats[$featureKey] ?? [] as $name => $vs) {
                if (
                    $vs['checkCount'] > 0
                    || $vs['requestCount'] > 0
                    || $vs['usedCount'] > 0
                    || $vs['viewedCount'] > 0
                    || $vs['errorCount'] > 0
                    || $vs['durationSumMs'] > 0
                    || $vs['errorDurationSumMs'] > 0
                ) {
                    $variantWire[$name] = $vs;
                }
            }
            $stats[] = [
                'feature' => $featureKey,
                'uniqueContextIdentifierEnabledCount' => count($this->uniqueUsageEnabled[$featureKey] ?? []),
                'uniqueContextIdentifierDisabledCount' => count($this->uniqueUsageDisabled[$featureKey] ?? []),
                'uniqueUsersUsedCount' => count($this->uniqueUsageUsed[$featureKey] ?? []),
                'uniqueUserHashes' => array_map('intval', array_keys($this->uniqueUserHashes[$featureKey] ?? [])),
                'uniqueViewedUserHashes' => array_map('intval', array_keys($this->uniqueViewedUserHashes[$featureKey] ?? [])),
                'variantStats' => $variantWire,
            ];
        }

        $payload = [
            'appKey' => $this->settings->appKey,
            'environment' => $this->settings->environment,
            'time' => GrpcClients::toProtobufTimestamp(),
            'stats' => $stats,
            'totalUniqueUsers' => count($this->applicationUniqueUserHashes),
            'uniqueUserHashes' => array_map('intval', array_keys($this->applicationUniqueUserHashes)),
            'processStartTime' => GrpcClients::toProtobufTimestamp($this->processStartTime),
            'instanceName' => $this->settings->instanceName ?? gethostname(),
        ];
        if ($this->settings->appVersion !== null && $this->settings->appVersion !== '') {
            $payload['appVersion'] = $this->settings->appVersion;
        }
        if ($this->definitionCacheHits > 0) {
            $payload['definitionCacheHits'] = $this->definitionCacheHits;
        }
        if ($this->definitionCacheMisses > 0) {
            $payload['definitionCacheMisses'] = $this->definitionCacheMisses;
        }

        if ($reset) {
            $this->variantStats = [];
            $this->uniqueUsageEnabled = [];
            $this->uniqueUsageDisabled = [];
            $this->uniqueUsageUsed = [];
            $this->uniqueUserHashes = [];
            $this->uniqueViewedUserHashes = [];
            $this->applicationUniqueUserHashes = [];
            $this->definitionCacheHits = 0;
            $this->definitionCacheMisses = 0;
        }

        return $payload;
    }

    /**
     * HTTPS JSON fallback keeps ISO-8601 time strings for gateway compatibility.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function toHttpJsonPayload(array $payload): array
    {
        $out = $payload;
        $out['time'] = date('c', (int) (($payload['time']['seconds'] ?? time())));
        if (isset($payload['processStartTime']['seconds'])) {
            $out['processStartTime'] = date('c', (int) $payload['processStartTime']['seconds']);
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
            $this->restoreStatFromPayload($stat);
        }
        foreach ($payload['uniqueUserHashes'] ?? [] as $hash) {
            $this->applicationUniqueUserHashes[(int) $hash] = true;
        }
        if (isset($payload['definitionCacheHits'])) {
            $this->definitionCacheHits += (int) $payload['definitionCacheHits'];
        }
        if (isset($payload['definitionCacheMisses'])) {
            $this->definitionCacheMisses += (int) $payload['definitionCacheMisses'];
        }
    }

    /**
     * @param array<string, mixed> $stat
     */
    private function restoreStatFromPayload(array $stat): void
    {
        $feature = (string) ($stat['feature'] ?? '');
        if ($feature === '') {
            return;
        }
        $this->restoreVariantStatsFromPayload($feature, $stat['variantStats'] ?? []);
        foreach ($stat['uniqueUserHashes'] ?? [] as $hash) {
            $this->uniqueUserHashes[$feature][(int) $hash] = true;
        }
        foreach ($stat['uniqueViewedUserHashes'] ?? [] as $hash) {
            $this->uniqueViewedUserHashes[$feature][(int) $hash] = true;
        }
    }

    /**
     * @param array<string, mixed> $variantStats
     */
    private function restoreVariantStatsFromPayload(string $feature, array $variantStats): void
    {
        foreach ($variantStats as $name => $vs) {
            if (!is_array($vs)) {
                continue;
            }
            if (!isset($this->variantStats[$feature][$name])) {
                $this->variantStats[$feature][$name] = $this->emptyVariantStats();
            }
            foreach (
                [
                    'checkCount',
                    'requestCount',
                    'usedCount',
                    'viewedCount',
                    'errorCount',
                    'durationSumMs',
                    'errorDurationSumMs',
                ] as $field
            ) {
                $this->variantStats[$feature][$name][$field] += (int) ($vs[$field] ?? 0);
            }
        }
    }

    /**
     * Union-merge unique-usage hash-set snapshots taken at send time.
     *
     * @param array<string, array<int, true>> $enabled
     * @param array<string, array<int, true>> $disabled
     * @param array<string, array<int, true>> $used
     */
    private function restoreUniqueUsageMaps(array $enabled, array $disabled, array $used): void
    {
        $this->mergeUniqueUsageMap($this->uniqueUsageEnabled, $enabled);
        $this->mergeUniqueUsageMap($this->uniqueUsageDisabled, $disabled);
        $this->mergeUniqueUsageMap($this->uniqueUsageUsed, $used);
    }

    /**
     * @param array<string, array<int, true>> $target
     * @param array<string, array<int, true>> $snapshot
     */
    private function mergeUniqueUsageMap(array &$target, array $snapshot): void
    {
        foreach ($snapshot as $featureKey => $hashes) {
            if (!isset($target[$featureKey])) {
                $target[$featureKey] = [];
            }
            foreach ($hashes as $hash => $_) {
                $target[$featureKey][(int) $hash] = true;
            }
        }
    }
}
