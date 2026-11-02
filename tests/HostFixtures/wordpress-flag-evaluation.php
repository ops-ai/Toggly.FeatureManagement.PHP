<?php

/**
 * Exercise WordPress plugin public flag evaluation against a live mock definitions API.
 * Note: no declare(strict_types=1) — wp eval-file wraps the file and rejects a leading
 * strict_types declaration (needed for WordPress 5.0 host CI).
 *
 * Expected WordPress options already configured:
 * - toggly_settings.app_key
 * - toggly_settings.environment
 * - toggly_settings.base_url (mock server origin, trailing slash ok)
 * - toggly_settings.use_signed_definitions = false
 *
 * Run via: wp eval-file tests/HostFixtures/wordpress-flag-evaluation.php
 */

if (!function_exists('toggly_is_enabled')) {
    throw new RuntimeException('Toggly plugin helper toggly_is_enabled() is not available.');
}

if (!class_exists(\Toggly\WordPress\TogglyPlugin::class)) {
    throw new RuntimeException('Toggly WordPress plugin class is not available.');
}

do_action('toggly_refresh_features');

$cases = [
    'premium-content' => true,
    'checkout-v2' => false,
    'empty-filters' => false,
    'missing-feature' => false,
];

foreach ($cases as $featureKey => $expected) {
    $actual = toggly_is_enabled($featureKey);
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            'Helper toggly_is_enabled(%s) expected %s, got %s.',
            $featureKey,
            $expected ? 'true' : 'false',
            $actual ? 'true' : 'false'
        ));
    }
}

$plugin = \Toggly\WordPress\TogglyPlugin::getInstance();
if ($plugin->isEnabled('premium-content') !== true) {
    throw new RuntimeException('TogglyPlugin::isEnabled(premium-content) expected true.');
}
if ($plugin->isEnabled('checkout-v2') !== false) {
    throw new RuntimeException('TogglyPlugin::isEnabled(checkout-v2) expected false.');
}

// Targeting uses the SDK's flat Audience.* parameter shape (see filter-parity fixtures).
// Nested JSON {"Audience":{"Users":[...]}} is a known non-evaluation gap — reported for owner decision.
$targetingOn = toggly_is_enabled('targeting-beta', ['identity' => 'wp-ci-user']);
$targetingOff = toggly_is_enabled('targeting-beta', ['identity' => 'other-user']);
if ($targetingOn !== true) {
    throw new RuntimeException('Targeting filter should enable for wp-ci-user.');
}
if ($targetingOff !== false) {
    throw new RuntimeException('Targeting filter should deny other-user when DefaultRolloutPercentage is 0.');
}

$shortcodeOn = do_shortcode('[toggly_feature name="premium-content"]VISIBLE[/toggly_feature]');
$shortcodeOff = do_shortcode('[toggly_feature name="checkout-v2"]HIDDEN[/toggly_feature]');
$shortcodeMissing = do_shortcode('[toggly_feature name="missing-feature"]ABSENT[/toggly_feature]');

if ($shortcodeOn !== 'VISIBLE') {
    throw new RuntimeException('Enabled shortcode should render content, got: ' . $shortcodeOn);
}
if ($shortcodeOff !== '') {
    throw new RuntimeException('Disabled shortcode should render empty, got: ' . $shortcodeOff);
}
if ($shortcodeMissing !== '') {
    throw new RuntimeException('Missing-feature shortcode should render empty, got: ' . $shortcodeMissing);
}

// No Gutenberg block surface is registered by this plugin today; helpers + shortcode
// are the exercised public evaluation APIs.
fwrite(STDOUT, "Toggly WordPress flag evaluation against mocked definitions API passed.\n");
