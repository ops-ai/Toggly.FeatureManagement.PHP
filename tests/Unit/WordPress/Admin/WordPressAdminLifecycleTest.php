<?php

// WordPress runtime stubs: tests/fixtures/WordPressRuntimeStubs.php (bootstrap).

namespace Toggly\FeatureManagement\Tests\Unit\WordPress\Admin {
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use Toggly\WordPress\Admin\SettingsPage;
    use Toggly\WordPress\TogglyPlugin;

    final class WordPressAdminLifecycleTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['toggly_wordpress_admin_actions'] = [];
            $GLOBALS['toggly_wordpress_admin_filters'] = [];
            $GLOBALS['toggly_wp_actions'] = [];
            $GLOBALS['toggly_wp_filters'] = [];
            $GLOBALS['toggly_wordpress_admin_options_page'] = null;
            $GLOBALS['toggly_wordpress_admin_settings'] = [];
            $GLOBALS['toggly_wordpress_admin_sections'] = [];
            $GLOBALS['toggly_wordpress_admin_fields'] = [];
            $GLOBALS['toggly_wordpress_admin_options'] = [];
            $GLOBALS['toggly_wordpress_admin_capabilities'] = [];
            $GLOBALS['toggly_wordpress_admin_rendered'] = [];
            $GLOBALS['toggly_wordpress_admin_shortcodes'] = [];
            $GLOBALS['toggly_wordpress_admin_scheduled'] = [];
            $GLOBALS['toggly_wordpress_admin_schedule_calls'] = [];
            $GLOBALS['toggly_wordpress_cache_values'] = [];
            $GLOBALS['toggly_wordpress_http_response'] = [
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers' => [],
                'body' => '',
            ];

            $this->resetPluginSingleton();
        }

        protected function tearDown(): void
        {
            $this->resetPluginSingleton();
        }

        public function testSettingsRegistrationAddsMenuSettingsSectionAndFields(): void
        {
            $page = new SettingsPage();
            $page->register();

            $this->assertSame(['admin_menu', 'admin_init'], array_column($GLOBALS['toggly_wordpress_admin_actions'], 'hook'));

            $page->addAdminMenu();
            $this->assertSame('manage_options', $GLOBALS['toggly_wordpress_admin_options_page']['capability']);
            $this->assertSame('toggly', $GLOBALS['toggly_wordpress_admin_options_page']['menuSlug']);

            $page->registerSettings();
            $this->assertSame('toggly_settings', $GLOBALS['toggly_wordpress_admin_settings'][0]['optionGroup']);
            $this->assertSame('toggly_main', $GLOBALS['toggly_wordpress_admin_sections'][0]['id']);
            $this->assertSame(
                ['app_key', 'environment', 'base_url', 'use_signed_definitions'],
                array_column($GLOBALS['toggly_wordpress_admin_fields'], 'id')
            );
        }

        public function testSettingsSanitizeAndRenderPersistedValues(): void
        {
            $page = new SettingsPage();
            $sanitized = $page->sanitizeSettings([
                'app_key' => ' <strong>app-key</strong> ',
                'environment' => ' <em>Staging</em> ',
                'base_url' => 'https://definitions.example.test/path?key=value',
                'use_signed_definitions' => '1',
            ]);

            $this->assertSame([
                'app_key' => 'app-key',
                'environment' => 'Staging',
                'base_url' => 'https://definitions.example.test/path?key=value',
                'use_signed_definitions' => true,
            ], $sanitized);

            $GLOBALS['toggly_wordpress_admin_options']['toggly_settings'] = $sanitized;
            ob_start();
            $page->renderAppKeyField();
            $page->renderEnvironmentField();
            $page->renderBaseUrlField();
            $page->renderSignedDefinitionsField();
            $output = (string) ob_get_clean();

            $this->assertStringContainsString('value="app-key"', $output);
            $this->assertStringContainsString('value="Staging"', $output);
            $this->assertStringContainsString('value="https://definitions.example.test/path?key=value"', $output);
            $this->assertStringContainsString('checked="checked"', $output);
        }

        public function testSettingsPageRequiresOptionsCapabilityBeforeRendering(): void
        {
            $page = new SettingsPage();
            ob_start();
            $page->renderSettingsPage();
            $this->assertSame('', ob_get_clean());
            $this->assertSame([], $GLOBALS['toggly_wordpress_admin_rendered']);

            $GLOBALS['toggly_wordpress_admin_capabilities']['manage_options'] = true;
            ob_start();
            $page->renderSettingsPage();
            $output = (string) ob_get_clean();

            $this->assertStringContainsString('<h1>Toggly Settings</h1>', $output);
            $this->assertSame(
                [['settings_fields', 'toggly_settings'], ['do_settings_sections', 'toggly'], ['submit_button']],
                $GLOBALS['toggly_wordpress_admin_rendered']
            );
        }

        public function testPluginInitializesWordPressRegistrationsAndAvoidsDuplicateCronSchedules(): void
        {
            $GLOBALS['toggly_wordpress_admin_options']['toggly_settings'] = [
                'app_key' => 'app-key',
                'environment' => 'Staging',
                'base_url' => 'https://definitions.example.test/',
                'use_signed_definitions' => true,
            ];
            $plugin = TogglyPlugin::getInstance();
            $plugin->init();

            $this->assertArrayHasKey('toggly_feature', $GLOBALS['toggly_wordpress_admin_shortcodes']);
            $this->assertCount(2, $GLOBALS['toggly_wordpress_admin_schedule_calls']);
            $this->assertSame(
                ['toggly_refresh_features', 'toggly_send_stats'],
                array_column($GLOBALS['toggly_wordpress_admin_schedule_calls'], 'hook')
            );
            $this->assertContains('cron_schedules', array_column($GLOBALS['toggly_wordpress_admin_filters'], 'hook'));

            $plugin->init();
            $this->assertCount(2, $GLOBALS['toggly_wordpress_admin_schedule_calls']);
        }

        public function testTemplateFunctionAndShortcodeUseThePluginFeatureDecision(): void
        {
            $GLOBALS['toggly_wordpress_cache_values']['toggly_features'] = [
                'features' => [
                    ['featureKey' => 'checkout', 'filters' => []],
                    ['featureKey' => 'visible', 'filters' => [['name' => 'AlwaysOn']]],
                ],
            ];
            $plugin = TogglyPlugin::getInstance();
            $plugin->init();

            $this->assertFalse(\toggly_is_enabled('checkout'));
            $this->assertTrue(\toggly_is_enabled('visible'));
            $this->assertSame('', $plugin->shortcodeTogglyFeature(['name' => 'checkout'], 'hidden'));
            $this->assertSame('shown', $plugin->shortcodeTogglyFeature(['name' => 'visible'], 'shown'));
            $this->assertSame('', $plugin->shortcodeTogglyFeature([], 'hidden'));
        }

        public function testPluginSingletonCanBeResetForFixtureIsolation(): void
        {
            $original = TogglyPlugin::getInstance();

            $this->resetPluginSingleton();

            $this->assertNotSame($original, TogglyPlugin::getInstance());
        }

        private function resetPluginSingleton(): void
        {
            $instance = new ReflectionProperty(TogglyPlugin::class, 'instance');
            // PHP 7.4 and 8.0 require explicit reflection access. PHP 8.1+
            // makes it implicit; avoiding the call there also avoids PHP 8.5's
            // deprecation warning.
            if (PHP_VERSION_ID < 80100) {
                $instance->setAccessible(true);
            }
            $instance->setValue(null, null);
        }
    }
}
