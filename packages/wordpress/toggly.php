<?php
/**
 * Plugin Name: Toggly Feature Management
 * Plugin URI: https://toggly.io/
 * Description: Feature flags around anything. A/B test entire features or sections of your website or store.
 * Version: 1.0.3
 * Requires at least: 5.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: opsAI LLC
 * Author URI: https://ops.ai/
 * License: MIT
 * Text Domain: toggly
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// PHP 7.4 polyfills for core helpers that still call PHP 8.0 string functions
// while both packages declare php ^7.4. Remove once core is 7.4-clean on the
// live-update path used after definition refresh.
if (!function_exists('str_starts_with')) {
    /**
     * @param mixed $haystack
     * @param mixed $needle
     */
    function str_starts_with($haystack, $needle): bool
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_contains')) {
    /**
     * @param mixed $haystack
     * @param mixed $needle
     */
    function str_contains($haystack, $needle): bool
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * @param mixed $haystack
     * @param mixed $needle
     */
    function str_ends_with($haystack, $needle): bool
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }

        $length = strlen($needle);

        return substr($haystack, -$length) === $needle;
    }
}

// Load Composer autoloader if available
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Initialize plugin
add_action('plugins_loaded', function () {
    if (class_exists(\Toggly\WordPress\TogglyPlugin::class)) {
        $plugin = \Toggly\WordPress\TogglyPlugin::getInstance();
        $plugin->init();

        // Register admin settings
        $settingsPage = new \Toggly\WordPress\Admin\SettingsPage();
        $settingsPage->register();
    }
});
