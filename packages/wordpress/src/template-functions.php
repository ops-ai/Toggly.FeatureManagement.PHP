<?php

if (!function_exists('toggly_is_enabled')) {
    function toggly_is_enabled(string $featureKey, ?array $context = null): bool
    {
        $plugin = \Toggly\WordPress\TogglyPlugin::getInstance();
        return $plugin->isEnabled($featureKey, $context);
    }
}
