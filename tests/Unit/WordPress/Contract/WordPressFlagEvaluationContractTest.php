<?php

/**
 * WordPress plugin flag-evaluation contract tests.
 *
 * Boots the real TogglyPlugin public surface (helper + shortcode + isEnabled),
 * mocks only the HTTP transport (wp_remote_request), and feeds Toggly API
 * definition / evaluated-variants payload shapes plus filter-parity fixtures.
 *
 * WordPress runtime stubs: tests/fixtures/WordPressRuntimeStubs.php (bootstrap).
 */

namespace Toggly\FeatureManagement\Tests\Unit\WordPress\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Core\HttpRequestMapper;
use Toggly\WordPress\TogglyPlugin;

final class WordPressFlagEvaluationContractTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['toggly_wordpress_admin_options'] = [
            'toggly_settings' => [
                'app_key' => 'contract-app',
                'environment' => 'Production',
                'base_url' => 'http://127.0.0.1:9/',
                'use_signed_definitions' => false,
            ],
            'toggly_snapshot_provider' => 'transient',
        ];
        $GLOBALS['toggly_wordpress_admin_actions'] = [];
        $GLOBALS['toggly_wordpress_admin_filters'] = [];
        $GLOBALS['toggly_wp_actions'] = [];
        $GLOBALS['toggly_wp_filters'] = [];
        $GLOBALS['toggly_wordpress_admin_shortcodes'] = [];
        $GLOBALS['toggly_wordpress_admin_scheduled'] = [];
        $GLOBALS['toggly_wordpress_admin_schedule_calls'] = [];
        $GLOBALS['toggly_wordpress_cache_values'] = [];
        $GLOBALS['toggly_wordpress_cache_adapter_calls'] = [];
        $GLOBALS['toggly_wordpress_current_user'] = null;
        $GLOBALS['toggly_wordpress_http_handler'] = null;
        $GLOBALS['toggly_wordpress_http_response'] = null;
        $GLOBALS['toggly_wordpress_http_request'] = null;

        $this->resetPluginSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetPluginSingleton();
        $GLOBALS['toggly_wordpress_http_handler'] = null;
    }

    public function testDefinitionsPayloadEvaluatesThroughHelperAndShortcode(): void
    {
        $definitions = [
            [
                'featureKey' => 'premium-content',
                'filters' => [['name' => 'AlwaysOn']],
            ],
            [
                'featureKey' => 'checkout-v2',
                'filters' => [['name' => 'AlwaysOff']],
            ],
            [
                'featureKey' => 'empty-filters',
                'filters' => [],
            ],
            [
                'featureKey' => 'targeting-beta',
                'filters' => [[
                    'name' => 'Targeting',
                    'parameters' => [
                        'Audience.DefaultRolloutPercentage' => 0,
                        'Audience.Users:0' => 'wp-ci-user',
                    ],
                ]],
            ],
        ];

        $this->bootPluginWithDefinitionsJson(json_encode($definitions, JSON_THROW_ON_ERROR));

        $this->assertTrue(toggly_is_enabled('premium-content'));
        $this->assertFalse(toggly_is_enabled('checkout-v2'));
        $this->assertFalse(toggly_is_enabled('empty-filters'));
        $this->assertFalse(toggly_is_enabled('missing-feature'));
        $this->assertTrue(toggly_is_enabled('targeting-beta', ['identity' => 'wp-ci-user']));
        $this->assertFalse(toggly_is_enabled('targeting-beta', ['identity' => 'other-user']));

        $plugin = TogglyPlugin::getInstance();
        $this->assertTrue($plugin->isEnabled('premium-content'));
        $this->assertFalse($plugin->isEnabled('checkout-v2'));

        $this->assertSame('VISIBLE', do_shortcode('[toggly_feature name="premium-content"]VISIBLE[/toggly_feature]'));
        $this->assertSame('', do_shortcode('[toggly_feature name="checkout-v2"]HIDDEN[/toggly_feature]'));
        $this->assertSame('', do_shortcode('[toggly_feature name="missing-feature"]ABSENT[/toggly_feature]'));
    }

    public function testEvaluatedVariantsPayloadShapeEvaluatesOnOffThroughPublicSurface(): void
    {
        // WordPress plugin settings do not expose enable_variants today. Flip the
        // core setting on the live FeatureProvider (test-only) so the real
        // evaluated-variants-signed path runs through the plugin's HTTP stack,
        // then assert on/off via helper + shortcode. Variant name/config are not
        // on the WordPress public surface (no getVariant helper).
        $evaluated = [
            'defs' => [
                'checkout-layout' => [
                    'enabled' => true,
                    'variant' => 'compact',
                    'configurationValue' => ['headline' => 'Shorter checkout'],
                ],
                'beta-banner' => [
                    'enabled' => false,
                    'variant' => 'control',
                    'configurationValue' => 'not exposed',
                ],
            ],
            'timestamp' => 42,
        ];

        $this->mockHttpJsonByPath([
            'evaluated-variants-signed/contract-app/Production' => json_encode($evaluated, JSON_THROW_ON_ERROR),
        ]);

        $plugin = TogglyPlugin::getInstance();
        $plugin->init();
        $this->disableLiveUpdatesAndEnableVariants($plugin);

        do_action('toggly_refresh_features');

        $this->assertTrue(toggly_is_enabled('checkout-layout'));
        $this->assertFalse(toggly_is_enabled('beta-banner'));
        $this->assertSame('ON', do_shortcode('[toggly_feature name="checkout-layout"]ON[/toggly_feature]'));
        $this->assertSame('', do_shortcode('[toggly_feature name="beta-banner"]OFF[/toggly_feature]'));

        $request = $GLOBALS['toggly_wordpress_http_request'] ?? null;
        $this->assertIsArray($request);
        $this->assertStringContainsString('evaluated-variants-signed/contract-app/Production', (string) $request['url']);
    }

    /**
     * @dataProvider filterParityFixtureProvider
     */
    #[DataProvider('filterParityFixtureProvider')]
    public function testFilterParityFixturesEvaluateThroughHelper(string $fixtureId, array $root): void
    {
        $definition = [
            'featureKey' => $root['featureKey'],
            'requirementType' => $root['requirementType'] ?? 'Any',
            'filters' => $root['filters'] ?? [],
        ];

        $this->bootPluginWithDefinitionsJson(json_encode([$definition], JSON_THROW_ON_ERROR));

        $context = $root['context'] ?? [];
        if (!is_array($context)) {
            $context = [];
        }
        $headers = $root['httpHeaders'] ?? null;
        if (is_array($headers) && $headers !== []) {
            $context = HttpRequestMapper::mergeIntoContext($headers, $context);
        }

        $expected = (bool) $root['expected'];
        $actual = toggly_is_enabled((string) $root['featureKey'], $context !== [] ? $context : null);
        $this->assertSame($expected, $actual, "filter-parity fixture {$fixtureId} via toggly_is_enabled");
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function filterParityFixtureProvider(): array
    {
        $directory = dirname(__DIR__, 3) . '/fixtures/filter-parity';
        $cases = [];
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);
        foreach ($files as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data) || !isset($data['id'], $data['featureKey'], $data['expected'])) {
                continue;
            }
            $cases[$data['id']] = [$data['id'], $data];
        }

        return $cases;
    }

    private function bootPluginWithDefinitionsJson(string $definitionsJson): void
    {
        $this->mockHttpJsonByPath([
            'definitions/contract-app/Production' => $definitionsJson,
        ]);

        $plugin = TogglyPlugin::getInstance();
        $plugin->init();
        $this->disableLiveUpdates($plugin);
        do_action('toggly_refresh_features');
    }

    /**
     * @param array<string, string> $pathToBody
     */
    private function mockHttpJsonByPath(array $pathToBody): void
    {
        $GLOBALS['toggly_wordpress_http_handler'] = static function (string $url, array $args) use ($pathToBody): array {
            foreach ($pathToBody as $path => $body) {
                if (str_contains($url, $path)) {
                    return [
                        'response' => ['code' => 200, 'message' => 'OK'],
                        'headers' => ['Content-Type' => 'application/json', 'ETag' => '"contract-v1"'],
                        'body' => $body,
                    ];
                }
            }

            // Avoid TogglyHttpClient 404 retry backoff for usage/metrics side calls.
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{}',
            ];
        };
    }

    private function disableLiveUpdates(TogglyPlugin $plugin): void
    {
        $settings = $this->pluginSettings($plugin);
        $settings->enableLiveUpdates = false;
    }

    private function disableLiveUpdatesAndEnableVariants(TogglyPlugin $plugin): void
    {
        $settings = $this->pluginSettings($plugin);
        $settings->enableLiveUpdates = false;
        $settings->enableVariants = true;
    }

    private function pluginSettings(TogglyPlugin $plugin): object
    {
        $pluginRef = new \ReflectionClass($plugin);
        $providerProp = $pluginRef->getProperty('featureProvider');
        if (PHP_VERSION_ID < 80100) {
            $providerProp->setAccessible(true);
        }
        $provider = $providerProp->getValue($plugin);
        $this->assertNotNull($provider);

        $providerRef = new \ReflectionClass($provider);
        $settingsProp = $providerRef->getProperty('settings');
        if (PHP_VERSION_ID < 80100) {
            $settingsProp->setAccessible(true);
        }

        return $settingsProp->getValue($provider);
    }

    private function resetPluginSingleton(): void
    {
        $ref = new \ReflectionClass(TogglyPlugin::class);
        $instance = $ref->getProperty('instance');
        if (PHP_VERSION_ID < 80100) {
            $instance->setAccessible(true);
        }
        $instance->setValue(null, null);
    }
}
