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
    // Evaluated-outcome shaped defs (AlwaysOn/AlwaysOff mirroring evaluated-variants).
    'checkout-layout' => true,
    'beta-banner' => false,
    // Filter-parity fixtures that need no request context.
    'parity-percentage-zero' => false,
    'parity-unknown-filter' => false,
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

$groupsOn = toggly_is_enabled('parity-targeting-groups', ['identity' => 'u', 'groups' => ['beta']]);
$groupsOff = toggly_is_enabled('parity-targeting-groups', ['identity' => 'u', 'groups' => ['other']]);
if ($groupsOn !== true) {
    throw new RuntimeException('parity-targeting-groups should enable for group beta.');
}
if ($groupsOff !== false) {
    throw new RuntimeException('parity-targeting-groups should deny without group beta.');
}

$claimsOn = toggly_is_enabled('parity-user-claims-match', ['identity' => 'u', 'claims' => ['role' => 'admin']]);
$claimsOff = toggly_is_enabled('parity-user-claims-match', ['identity' => 'u', 'claims' => ['role' => 'user']]);
if ($claimsOn !== true) {
    throw new RuntimeException('parity-user-claims-match should enable for role=admin.');
}
if ($claimsOff !== false) {
    throw new RuntimeException('parity-user-claims-match should deny for role=user.');
}

$browserOn = toggly_is_enabled('parity-browser-family-match', [
    'identity' => 'u',
    'request' => [
        'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ],
]);
if ($browserOn !== true) {
    throw new RuntimeException('parity-browser-family-match should enable for Chrome UA.');
}

$shortcodeOn = do_shortcode('[toggly_feature name="premium-content"]VISIBLE[/toggly_feature]');
$shortcodeOff = do_shortcode('[toggly_feature name="checkout-v2"]HIDDEN[/toggly_feature]');
$shortcodeMissing = do_shortcode('[toggly_feature name="missing-feature"]ABSENT[/toggly_feature]');
$shortcodeVariantOn = do_shortcode('[toggly_feature name="checkout-layout"]VARIANT_ON[/toggly_feature]');
$shortcodeVariantOff = do_shortcode('[toggly_feature name="beta-banner"]VARIANT_OFF[/toggly_feature]');

if ($shortcodeOn !== 'VISIBLE') {
    throw new RuntimeException('Enabled shortcode should render content, got: ' . $shortcodeOn);
}
if ($shortcodeOff !== '') {
    throw new RuntimeException('Disabled shortcode should render empty, got: ' . $shortcodeOff);
}
if ($shortcodeMissing !== '') {
    throw new RuntimeException('Missing-feature shortcode should render empty, got: ' . $shortcodeMissing);
}
if ($shortcodeVariantOn !== 'VARIANT_ON') {
    throw new RuntimeException('Evaluated-outcome AlwaysOn shortcode should render, got: ' . $shortcodeVariantOn);
}
if ($shortcodeVariantOff !== '') {
    throw new RuntimeException('Evaluated-outcome AlwaysOff shortcode should render empty, got: ' . $shortcodeVariantOff);
}

// No Gutenberg block surface is registered by this plugin today; helpers + shortcode
// are the exercised public evaluation APIs. Variant name/config accessors are not
// exposed on the WordPress public surface (no getVariant helper / enable_variants setting).
fwrite(STDOUT, "Toggly WordPress flag evaluation against mocked definitions API passed.\n");
