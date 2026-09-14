<?php

namespace Toggly\WordPress\Storage;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * WordPress cache adapter for PSR-16 v3 hosts.
 *
 * This file is loaded only when the installed CacheInterface declares v3
 * signatures, keeping the retained PHP 7.4 and PSR-16 v1/v2 path parseable.
 */
class WordPressCacheAdapterV3 implements CacheInterface
{
    private int $defaultTtl;

    public function __construct(int $defaultTtl = 3600)
    {
        $this->defaultTtl = $defaultTtl;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = get_transient($key);
        return $value !== false ? $value : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        return set_transient($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return delete_transient($key);
    }

    public function clear(): bool
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_transient_timeout_%'");
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    public function has(string $key): bool
    {
        return get_transient($key) !== false;
    }
}
