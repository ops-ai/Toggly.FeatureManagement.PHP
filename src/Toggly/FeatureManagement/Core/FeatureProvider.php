<?php

namespace Toggly\FeatureManagement\Core;

use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureProviderInterface;
use Toggly\FeatureManagement\Contracts\FeatureSnapshotProviderInterface;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Contracts\IFeatureExperimentProvider;
use Toggly\FeatureManagement\Contracts\SecureFeatureProviderInterface;
use Toggly\FeatureManagement\Exceptions\SignatureVerificationException;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Http\WebSocketClient;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Models\SignedDefinitionsResponse;
use Toggly\FeatureManagement\Security\EcdsaSignatureVerifier;
use Toggly\FeatureManagement\Security\JwkManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main feature provider that fetches and manages feature definitions from Toggly
 */
class FeatureProvider implements FeatureProviderInterface, SecureFeatureProviderInterface, IFeatureExperimentProvider
{
    private TogglySettings $settings;
    private TogglyHttpClient $httpClient;
    private ?FeatureSnapshotProviderInterface $snapshotProvider;
    private FeatureStateServiceInterface $featureStateService;
    private ?EcdsaSignatureVerifier $signatureVerifier;
    private ?JwkManager $jwkManager;
    private LoggerInterface $logger;
    private WebSocketClient $webSocketClient;

    /**
     * @var FeatureDefinition[] Cache of feature definitions by key
     */
    private array $definitions = [];

    /**
     * @var array<string, string[]> Map of metric keys to feature keys
     */
    private array $experiments = [];

    /**
     * @var string[] Set of secured feature keys
     */
    private array $secureFeatures = [];

    /**
     * Server-evaluated variant entries keyed by feature (enableVariants mode only).
     *
     * @var array<string, array{enabled: bool, variant: string, configurationValue: mixed}>
     */
    private array $variantEntries = [];

    /**
     * Per-request identity override (takes precedence over {@see TogglySettings::$identity}).
     */
    private ?string $identityOverride = null;

    /**
     * Identity last used for a variants HTTP fetch (for ETag isolation on shared HTTP clients).
     */
    private ?string $lastVariantsFetchIdentity = null;

    private bool $loaded = false;
    private ?int $lastDefinitionsTimestamp = null;
    private ?string $lastError = null;
    private ?int $lastErrorTime = null;
    private ?int $lastRefresh = null;
    private bool $refreshInProgress = false;
    private ?int $lastFallbackPoll = null;
    private int $fallbackInterval;

    /** Fallback poll interval when WebSocket is connected (20 minutes) */
    private const WS_FALLBACK_INTERVAL = 1200;

    public function __construct(
        TogglySettings $settings,
        TogglyHttpClient $httpClient,
        FeatureStateServiceInterface $featureStateService,
        ?FeatureSnapshotProviderInterface $snapshotProvider = null,
        ?LoggerInterface $logger = null
    ) {
        $this->settings = $settings;
        $this->httpClient = $httpClient;
        $this->snapshotProvider = $snapshotProvider;
        $this->featureStateService = $featureStateService;
        $this->logger = $logger ?? new NullLogger();
        $this->webSocketClient = new WebSocketClient($this->logger);
        $this->fallbackInterval = self::WS_FALLBACK_INTERVAL;

        // Initialize security components if signed definitions are enabled
        if ($settings->useSignedDefinitions) {
            $this->jwkManager = new JwkManager(
                $httpClient,
                $settings->getBaseUrl(),
                $snapshotProvider,
                $settings->allowedKeyIds,
                $this->logger
            );
            $this->signatureVerifier = new EcdsaSignatureVerifier($this->jwkManager, $this->logger);
        }

        // Load snapshot on startup
        $this->loadSnapshot();

        // Start refresh timer (using a simple approach - in production, use a proper scheduler)
        $this->startRefreshTimer();
    }

    /**
     * Start the refresh timer
     */
    private function startRefreshTimer(): void
    {
        // In a real implementation, you'd use a proper scheduler or background job
        // For now, we'll trigger refresh on first access and rely on external scheduling
        // In Laravel, this would be handled by a scheduled task
        // In WordPress, this would be handled by WP Cron
    }

    /**
     * Load snapshot from provider
     */
    private function loadSnapshot(): void
    {
        if ($this->snapshotProvider === null) {
            return;
        }

        try {
            $snapshot = $this->snapshotProvider->getFeaturesSnapshot();
            if ($snapshot['features'] === null || empty($snapshot['features'])) {
                return;
            }

            $features = $snapshot['features'];

            // Verify signature if using signed definitions
            if ($this->settings->useSignedDefinitions) {
                if ($snapshot['signature'] === null || $snapshot['keyId'] === null || $snapshot['timestamp'] === null) {
                    $this->logger->warning('Snapshot is missing required signature fields');
                    return;
                }

                try {
                    $jsonData = json_encode(array_map(fn($f) => $f->toArray(), $features), JSON_UNESCAPED_SLASHES);
                    $valid = $this->signatureVerifier->verifySnapshot(
                        $jsonData,
                        $snapshot['signature'],
                        $snapshot['keyId'],
                        $snapshot['timestamp']
                    );

                    if (!$valid) {
                        $this->logger->error('Invalid signature in snapshot');
                        return;
                    }
                } catch (SignatureVerificationException $e) {
                    $this->logger->error('Signature verification failed for snapshot', ['error' => $e->getMessage()]);
                    return;
                }
            }

            // Load definitions from snapshot
            foreach ($features as $featureDefinition) {
                $this->definitions[$featureDefinition->featureKey] = $featureDefinition;

                // Track secured features
                if ($featureDefinition->securedFeature) {
                    $this->secureFeatures[$featureDefinition->featureKey] = true;
                } else {
                    unset($this->secureFeatures[$featureDefinition->featureKey]);
                }

                // Update feature state
                $isEnabled = $this->isAlwaysOn($featureDefinition);
                if ($this->featureStateService instanceof FeatureStateService) {
                    $this->featureStateService->updateFeatureState($featureDefinition->featureKey, $isEnabled);
                }
            }

            // Update experiments mapping
            $this->updateExperimentsMapping($features);

            if ($this->featureStateService instanceof FeatureStateService) {
                $this->featureStateService->notifyDefinitionsChanged();
            }
            $this->loaded = true;
        } catch (\Exception $e) {
            $this->logger->error('Error loading from snapshot', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Refresh features from API
     */
    public function refreshFeatures(bool $force = false): void
    {
        if ($this->refreshInProgress) {
            $this->logger->debug('Refresh already in progress, skipping');
            return;
        }

        if (!$force && $this->webSocketClient->isRunning()) {
            $now = time();
            if ($this->lastFallbackPoll !== null && ($now - $this->lastFallbackPoll) < $this->fallbackInterval) {
                return;
            }
            $this->lastFallbackPoll = $now;
        }

        $this->refreshInProgress = true;

        try {
            // Ensure initial load happens
            if (!$this->loaded) {
                $this->loadSnapshot();
            }

            if ($this->settings->enableVariants) {
                $identity = $this->getVariantIdentity();
                if ($this->lastVariantsFetchIdentity !== $identity) {
                    $this->httpClient->clearETag();
                }
                $this->lastVariantsFetchIdentity = $identity;

                $path = sprintf(
                    'evaluated-variants-signed/%s/%s',
                    rawurlencode($this->settings->appKey),
                    rawurlencode($this->settings->environment)
                );
                $query = [];
                if ($identity !== null && $identity !== '') {
                    $query['userId'] = $identity;
                }
                if ($query !== []) {
                    $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                }
            } elseif ($this->settings->useSignedDefinitions) {
                $path = "definitions-signed/{$this->settings->appKey}/{$this->settings->environment}";
            } else {
                $path = "definitions/{$this->settings->appKey}/{$this->settings->environment}";
            }

            $response = $this->httpClient->get($path);

            // Handle 304 Not Modified
            if ($response === null) {
                $this->logger->debug('Features not modified (304)');
                return;
            }

            $response->getBody()->rewind();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($data === null) {
                $this->logger->warning('Received empty or invalid response from Toggly');
                return;
            }

            if ($this->settings->enableVariants) {
                $this->processEvaluatedVariantsResponse($body, $data);
                return;
            }

            $features = [];

            if ($this->settings->useSignedDefinitions) {
                $signedResponse = new SignedDefinitionsResponse($data);

                // Check timestamp
                if ($this->lastDefinitionsTimestamp !== null && $signedResponse->timestamp < $this->lastDefinitionsTimestamp) {
                    $this->logger->warning('Received definitions with older timestamp', [
                        'current' => $this->lastDefinitionsTimestamp,
                        'received' => $signedResponse->timestamp,
                    ]);
                    return;
                }

                // Verify signature
                try {
                    // Extract the raw defs value from the JSON body for signature verification
                    $rawDefs = $this->extractSignedDefsFromBody($body);

                    $valid = $this->signatureVerifier->verify(
                        $rawDefs,
                        $signedResponse->signature,
                        $signedResponse->kid,
                        $signedResponse->timestamp
                    );

                    if (!$valid) {
                        $this->logger->error('Invalid signature');
                        return;
                    }
                } catch (SignatureVerificationException $e) {
                    $this->logger->error('Signature verification failed', ['error' => $e->getMessage()]);
                    return;
                }

                $features = $signedResponse->defs;
                $this->lastDefinitionsTimestamp = $signedResponse->timestamp;

                // Save snapshot
                if ($this->snapshotProvider !== null) {
                    $this->snapshotProvider->saveSnapshot(
                        $features,
                        $signedResponse->signature,
                        $signedResponse->kid,
                        $signedResponse->timestamp
                    );
                }
            } else {
                // Unsigned definitions
                $features = array_map(function ($def) {
                    return new FeatureDefinition($def);
                }, $data);

                // Save snapshot
                if ($this->snapshotProvider !== null) {
                    $this->snapshotProvider->saveSnapshot($features);
                }
            }

            // Update definitions
            foreach ($features as $featureDefinition) {
                $this->definitions[$featureDefinition->featureKey] = $featureDefinition;

                // Track secured features
                if ($featureDefinition->securedFeature) {
                    $this->secureFeatures[$featureDefinition->featureKey] = true;
                } else {
                    unset($this->secureFeatures[$featureDefinition->featureKey]);
                }

                // Update feature state
                $isEnabled = $this->isAlwaysOn($featureDefinition);
                if ($this->featureStateService instanceof FeatureStateService) {
                    $this->featureStateService->updateFeatureState($featureDefinition->featureKey, $isEnabled);
                }
            }

            // Update experiments mapping
            $this->updateExperimentsMapping($features);

            if ($this->featureStateService instanceof FeatureStateService) {
                $this->featureStateService->notifyDefinitionsChanged();
            }
            $this->loaded = true;
            $this->lastRefresh = time();

            // Try to establish WebSocket connection
            $this->tryConnectWebSocket();
        } catch (\Exception $e) {
            $this->logger->error('Error refreshing features list', ['error' => $e->getMessage()]);
            $this->lastError = $e->getMessage();
            $this->lastErrorTime = time();
        } finally {
            $this->refreshInProgress = false;
        }
    }

    /**
     * Try to connect WebSocket for live updates
     */
    private function tryConnectWebSocket(): void
    {
        if (!$this->settings->enableLiveUpdates) {
            return;
        }

        if (!$this->webSocketClient->isAvailable() || $this->webSocketClient->isRunning()) {
            return;
        }

        try {
            $baseUrl = rtrim($this->settings->getBaseUrl(), '/');
            if (str_starts_with($baseUrl, 'https://')) {
                $wsBase = 'wss://' . substr($baseUrl, 8);
            } elseif (str_starts_with($baseUrl, 'http://')) {
                $wsBase = 'ws://' . substr($baseUrl, 7);
            } else {
                $wsBase = $baseUrl;
            }
            $wsUrl = $wsBase . "/{$this->settings->appKey}/ws";

            $connected = $this->webSocketClient->connect($wsUrl, function () {
                $this->logger->info('WebSocket update received, refreshing features');
                $this->refreshFeatures(true);
            });

            if ($connected) {
                $this->logger->info('WebSocket connected for live updates');
                $this->lastFallbackPoll = time();
            }
        } catch (\Exception $e) {
            $this->logger->warning('WebSocket not available, continuing without it', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Update experiments mapping from features
     * @param FeatureDefinition[] $features
     */
    private function updateExperimentsMapping(array $features): void
    {
        $this->experiments = [];

        foreach ($features as $feature) {
            if ($feature->metrics === null || empty($feature->metrics)) {
                continue;
            }

            foreach ($feature->metrics as $metricKey) {
                if (!isset($this->experiments[$metricKey])) {
                    $this->experiments[$metricKey] = [];
                }
                $this->experiments[$metricKey][] = $feature->featureKey;
            }
        }
    }

    /**
     * Override identity for evaluated-variants requests (call from request middleware when the provider is a singleton).
     */
    public function setIdentity(?string $identity): void
    {
        $this->identityOverride = $identity;
    }

    private function getVariantIdentity(): ?string
    {
        if ($this->identityOverride !== null && $this->identityOverride !== '') {
            return $this->identityOverride;
        }
        $id = $this->settings->identity;
        return $id !== null && $id !== '' ? $id : null;
    }

    /**
     * Parse and apply evaluated-variants-signed response (defs object keyed by feature).
     */
    private function processEvaluatedVariantsResponse(string $body, array $data): void
    {
        $defsRaw = $data['defs'] ?? null;
        if (!is_array($defsRaw)) {
            $this->logger->warning('evaluated-variants-signed response missing defs object');
            return;
        }

        $timestamp = (int)($data['timestamp'] ?? 0);
        $signature = (string)($data['signature'] ?? '');
        $kid = (string)($data['kid'] ?? '');

        if ($this->settings->useSignedDefinitions && $this->signatureVerifier !== null) {
            if ($signature !== '' && $kid !== '') {
                if ($this->lastDefinitionsTimestamp !== null && $timestamp < $this->lastDefinitionsTimestamp) {
                    $this->logger->warning('Received variant definitions with older timestamp', [
                        'current' => $this->lastDefinitionsTimestamp,
                        'received' => $timestamp,
                    ]);
                    return;
                }

                try {
                    $rawDefs = $this->extractSignedDefsFromBody($body);
                    $valid = $this->signatureVerifier->verify(
                        $rawDefs,
                        $signature,
                        $kid,
                        $timestamp
                    );

                    if (!$valid) {
                        $this->logger->error('Invalid signature on evaluated-variants response');
                        return;
                    }
                } catch (SignatureVerificationException $e) {
                    $this->logger->error('Signature verification failed for evaluated-variants', ['error' => $e->getMessage()]);
                    return;
                }
            } else {
                $this->logger->notice('evaluated-variants-signed response has no signature; skipping verification');
            }
        }

        $this->variantEntries = [];
        $features = [];

        foreach ($defsRaw as $featureKey => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string)$featureKey;
            $enabled = (bool)($row['enabled'] ?? false);
            $variantName = isset($row['variant']) ? (string)$row['variant'] : '';
            $configurationValue = $row['configurationValue'] ?? null;

            $this->variantEntries[$key] = [
                'enabled' => $enabled,
                'variant' => $variantName,
                'configurationValue' => $configurationValue,
            ];

            $filters = $enabled ? [['name' => 'AlwaysOn']] : [['name' => 'AlwaysOff']];
            $features[] = new FeatureDefinition([
                'featureKey' => $key,
                'filters' => $filters,
            ]);
        }

        $this->lastDefinitionsTimestamp = $timestamp > 0 ? $timestamp : $this->lastDefinitionsTimestamp;

        if ($this->snapshotProvider !== null) {
            $this->snapshotProvider->saveSnapshot(
                $features,
                $signature !== '' ? $signature : null,
                $kid !== '' ? $kid : null,
                $timestamp > 0 ? $timestamp : null
            );
        }

        foreach ($features as $featureDefinition) {
            $this->definitions[$featureDefinition->featureKey] = $featureDefinition;

            if ($featureDefinition->securedFeature) {
                $this->secureFeatures[$featureDefinition->featureKey] = true;
            } else {
                unset($this->secureFeatures[$featureDefinition->featureKey]);
            }

            $isEnabled = $this->isAlwaysOn($featureDefinition);
            if ($this->featureStateService instanceof FeatureStateService) {
                $this->featureStateService->updateFeatureState($featureDefinition->featureKey, $isEnabled);
            }
        }

        $this->updateExperimentsMapping($features);

        if ($this->featureStateService instanceof FeatureStateService) {
            $this->featureStateService->notifyDefinitionsChanged();
        }
        $this->loaded = true;
        $this->lastRefresh = time();

        $this->tryConnectWebSocket();
    }

    /**
     * @return array{name: string, configurationValue: mixed}|null
     */
    public function getVariant(string $featureKey): ?array
    {
        if (!$this->settings->enableVariants) {
            return null;
        }

        if (!isset($this->variantEntries[$featureKey])) {
            return null;
        }

        $entry = $this->variantEntries[$featureKey];
        if (!$entry['enabled']) {
            return null;
        }

        return [
            'name' => $entry['variant'],
            'configurationValue' => $entry['configurationValue'],
        ];
    }

    /**
     * @return mixed|null
     */
    public function getVariantValue(string $featureKey)
    {
        $variant = $this->getVariant($featureKey);
        if ($variant === null) {
            return null;
        }

        return $variant['configurationValue'] ?? null;
    }

    /**
     * Extract the raw JSON bytes for the signed "defs" value (array or object) from the response body.
     */
    private function extractSignedDefsFromBody(string $body): string
    {
        if (preg_match('/"defs"\s*:\s*([\[\{])/u', $body, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return '[]';
        }

        $openChar = $matches[1][0];
        $openIdx = $matches[1][1];

        if ($openChar === '[') {
            return $this->extractBalancedBrackets($body, $openIdx);
        }

        return $this->extractBalancedBraces($body, $openIdx);
    }

    /**
     * Extract a balanced {...} substring starting at $openIdx.
     */
    private function extractBalancedBraces(string $json, int $openIdx): string
    {
        $depth = 0;
        $inString = false;
        $len = strlen($json);
        $i = $openIdx;

        while ($i < $len) {
            $ch = $json[$i];

            if ($ch === '\\' && $inString) {
                $i += 2;
                continue;
            }

            if ($ch === '"') {
                $inString = !$inString;
            } elseif (!$inString) {
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}' && --$depth === 0) {
                    return substr($json, $openIdx, $i - $openIdx + 1);
                }
            }

            $i++;
        }

        return '{}';
    }

    /**
     * Extract a balanced [...] substring starting at $openIdx.
     */
    private function extractBalancedBrackets(string $json, int $openIdx): string
    {
        $depth = 0;
        $inString = false;
        $len = strlen($json);
        $i = $openIdx;

        while ($i < $len) {
            $ch = $json[$i];

            if ($ch === '\\' && $inString) {
                $i += 2; // skip escaped character
                continue;
            }

            if ($ch === '"') {
                $inString = !$inString;
            } elseif (!$inString) {
                if ($ch === '[') {
                    $depth++;
                } elseif ($ch === ']' && --$depth === 0) {
                    return substr($json, $openIdx, $i - $openIdx + 1);
                }
            }

            $i++;
        }

        return '[]';
    }

    private function isAlwaysOn(FeatureDefinition $feature): bool
    {
        foreach ($feature->filters as $filter) {
            if ($filter->name === 'AlwaysOn') {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getAllFeatureDefinitions(): array
    {
        // Wait for initial load with timeout
        if (!$this->loaded) {
            $maxWaitTime = 2.5; // seconds
            $elapsed = 0;
            $delay = 0.1; // 100ms

            while (!$this->loaded && $elapsed < $maxWaitTime) {
                usleep((int)($delay * 1000000)); // Convert to microseconds
                $elapsed += $delay;
            }
        }

        return array_values($this->definitions);
    }

    /**
     * @inheritDoc
     */
    public function getFeatureDefinition(string $featureName): ?FeatureDefinition
    {
        // Wait for initial load with timeout
        if (!$this->loaded) {
            $maxWaitTime = 2.5; // seconds
            $elapsed = 0;
            $delay = 0.1; // 100ms

            while (!$this->loaded && $elapsed < $maxWaitTime) {
                usleep((int)($delay * 1000000));
                $elapsed += $delay;
            }
        }

        if (isset($this->definitions[$featureName])) {
            return $this->definitions[$featureName];
        }

        // Return default definition if undefined
        if ($this->settings->undefinedEnabledOnDevelopment) {
            $def = new FeatureDefinition([
                'featureKey' => $featureName,
                'filters' => [['name' => 'AlwaysOn']],
            ]);
            return $def;
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getFeaturesForMetric(string $metricKey): ?array
    {
        return $this->experiments[$metricKey] ?? null;
    }

    /**
     * Check if a feature is secured
     */
    public function isFeatureSecured(string $featureKey): bool
    {
        return isset($this->secureFeatures[$featureKey]);
    }

    /**
     * Get the feature state service (for internal use)
     */
    public function getFeatureStateService(): FeatureStateServiceInterface
    {
        return $this->featureStateService;
    }

    /**
     * Process pending WebSocket messages (non-blocking).
     *
     * In long-running processes call this periodically (e.g. every loop
     * iteration, or on a timer) so that incoming update signals are consumed
     * promptly.  In short-lived PHP-FPM requests this is a no-op.
     */
    public function tick(): void
    {
        if ($this->webSocketClient->isRunning() || $this->webSocketClient->isAvailable()) {
            $this->webSocketClient->tick();
        }
    }

    /**
     * Gracefully shut down the provider, closing the WebSocket connection.
     */
    public function shutdown(): void
    {
        $this->webSocketClient->disconnect();
    }

    /**
     * Get debug information
     */
    public function getDebugInfo(): array
    {
        return [
            'app_key' => $this->settings->appKey,
            'environment' => $this->settings->environment,
            'enable_variants' => $this->settings->enableVariants,
            'variants_count' => count($this->variantEntries),
            'definitions_count' => count($this->definitions),
            'experiments_count' => count($this->experiments),
            'last_error' => $this->lastError,
            'last_error_time' => $this->lastErrorTime,
            'last_refresh' => $this->lastRefresh,
            'websocket_running' => $this->webSocketClient->isRunning(),
            'websocket_available' => $this->webSocketClient->isAvailable(),
            'live_updates_enabled' => $this->settings->enableLiveUpdates,
            'fallback_interval' => $this->fallbackInterval,
            'loaded' => $this->loaded,
        ];
    }
}
