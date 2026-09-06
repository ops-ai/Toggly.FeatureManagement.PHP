<?php

namespace Toggly\FeatureManagement\Config;

/**
 * Configuration settings for Toggly Feature Management
 */
class TogglySettings
{
    /**
     * Toggly App Key. Get it from the App Settings page on toggly.io
     */
    public string $appKey = '';

    /**
     * Name of the environment. Case sensitive
     */
    public string $environment = 'Production';

    /**
     * Use signed definitions to get feature updates
     */
    public bool $useSignedDefinitions = false;

    /**
     * When true, fetches evaluated feature variants from evaluated-variants-signed (server-side evaluation).
     * Requires {@see $useSignedDefinitions} for signature verification when the API returns a signature.
     */
    public bool $enableVariants = false;

    /**
     * Identity (user id) sent as userId when fetching evaluated variants. Override per request via
     * {@see FeatureProvider::setIdentity()} when the provider is shared across users.
     */
    public ?string $identity = null;

    /**
     * Base URL of the toggly instance. Leave blank unless you have a reason to change
     */
    public ?string $baseUrl = null;

    /**
     * The current version of the application. Used to track deployments
     */
    public ?string $appVersion = null;

    /**
     * Hostname or instance name of the application. Useful in load-balanced and multi-server setups
     */
    public ?string $instanceName = null;

    /**
     * Undefined features should be treated as AlwaysOn on development
     */
    public bool $undefinedEnabledOnDevelopment = false;

    /**
     * Whitelist of allowed key IDs for signed definitions
     * @var string[]|null
     */
    public ?array $allowedKeyIds = null;

    /**
     * Refresh interval in seconds (default: 300 = 5 minutes)
     */
    public int $refreshInterval = 300;

    /**
     * Enable live updates via WebSocket (default: true)
     * Only effective in long-running processes (CLI, Laravel Octane, ReactPHP, etc.)
     * Automatically disabled in PHP-FPM / traditional request-per-process environments
     */
    public bool $enableLiveUpdates = true;

    /**
     * Optional error callback invoked on refresh/snapshot failures (OPS-277 parity).
     * Signature: function (string $message, ?\Throwable $exception): void
     * @var callable|null
     */
    public $onError = null;

    public function __construct(array $config = [])
    {
        if (isset($config['app_key'])) {
            $this->appKey = $config['app_key'];
        }
        if (isset($config['environment'])) {
            $this->environment = $config['environment'];
        }
        if (isset($config['use_signed_definitions'])) {
            $this->useSignedDefinitions = (bool)$config['use_signed_definitions'];
        }
        if (isset($config['enable_variants'])) {
            $this->enableVariants = (bool)$config['enable_variants'];
        }
        if (isset($config['identity'])) {
            $this->identity = $config['identity'] !== null && $config['identity'] !== ''
                ? (string)$config['identity']
                : null;
        }
        if (isset($config['base_url'])) {
            $this->baseUrl = $config['base_url'];
        }
        if (isset($config['app_version'])) {
            $this->appVersion = $config['app_version'];
        }
        if (isset($config['instance_name'])) {
            $this->instanceName = $config['instance_name'];
        }
        if (isset($config['undefined_enabled_on_development'])) {
            $this->undefinedEnabledOnDevelopment = (bool)$config['undefined_enabled_on_development'];
        }
        if (isset($config['allowed_key_ids'])) {
            $this->allowedKeyIds = is_array($config['allowed_key_ids'])
                ? $config['allowed_key_ids']
                : explode(',', $config['allowed_key_ids']);
        }
        if (isset($config['refresh_interval'])) {
            $this->refreshInterval = (int)$config['refresh_interval'];
        }
        if (isset($config['enable_live_updates'])) {
            $this->enableLiveUpdates = (bool)$config['enable_live_updates'];
        }
        if (isset($config['on_error']) && is_callable($config['on_error'])) {
            $this->onError = $config['on_error'];
        }
    }

    /**
     * Get the base URL, defaulting to https://definitions.toggly.io/
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl ?? 'https://definitions.toggly.io/';
    }
}
