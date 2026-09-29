<?php

namespace {
    if (!function_exists('get_transient')) {
        function get_transient(string $key): mixed
        {
            return $GLOBALS['toggly_wordpress_cache_values'][$key] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient(string $key, mixed $value, int $expiration = 0): bool
        {
            $GLOBALS['toggly_wordpress_cache_adapter_calls'][] = compact('key', 'value', 'expiration');
            $result = $GLOBALS['toggly_wordpress_cache_set_results'][$key] ?? true;
            if ($result) {
                $GLOBALS['toggly_wordpress_cache_values'][$key] = $value;
            }

            return $result;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            $GLOBALS['toggly_wordpress_cache_delete_calls'][] = $key;
            $result = $GLOBALS['toggly_wordpress_cache_delete_results'][$key] ?? true;
            if ($result) {
                unset($GLOBALS['toggly_wordpress_cache_values'][$key]);
            }

            return $result;
        }
    }
}

namespace Toggly\FeatureManagement\Tests\Unit\WordPress\Storage {
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;
    use Toggly\WordPress\Storage\WordPressCacheAdapterV3;

    final class WordPressCacheAdapterTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['toggly_wordpress_cache_values'] = [];
            $GLOBALS['toggly_wordpress_cache_adapter_calls'] = [];
            $GLOBALS['toggly_wordpress_cache_set_results'] = [];
            $GLOBALS['toggly_wordpress_cache_delete_calls'] = [];
            $GLOBALS['toggly_wordpress_cache_delete_results'] = [];
            $GLOBALS['wpdb'] = new RecordingWordPressDatabase();
        }

        #[RunInSeparateProcess]
        public function testLegacyAdapterReadsWritesAndUsesConfiguredExpiry(): void
        {
            $adapter = $this->legacyAdapter(600);

            $this->assertSame('fallback', $adapter->get('missing', 'fallback'));
            $this->assertFalse($adapter->has('missing'));
            $this->assertTrue($adapter->set('default', 'value'));
            $this->assertTrue($adapter->set('custom', 'value', 45));

            $this->assertSame('value', $adapter->get('default'));
            $this->assertTrue($adapter->has('custom'));
            $this->assertSame([600, 45], array_column($GLOBALS['toggly_wordpress_cache_adapter_calls'], 'expiration'));
        }

        #[RunInSeparateProcess]
        public function testLegacyAdapterReportsBulkWriteAndDeletionFailuresWhileContinuing(): void
        {
            $adapter = $this->legacyAdapter();
            $GLOBALS['toggly_wordpress_cache_set_results']['blocked'] = false;
            $GLOBALS['toggly_wordpress_cache_delete_results']['blocked'] = false;
            $GLOBALS['toggly_wordpress_cache_values'] = ['blocked' => 'old-value'];

            $this->assertFalse($adapter->setMultiple(['blocked' => 'value', 'stored' => 'value'], 30));
            $this->assertSame([30, 30], array_column($GLOBALS['toggly_wordpress_cache_adapter_calls'], 'expiration'));
            $this->assertSame(['blocked', 'stored'], array_column($GLOBALS['toggly_wordpress_cache_adapter_calls'], 'key'));
            $this->assertTrue($adapter->has('stored'));
            $this->assertFalse($adapter->deleteMultiple(['blocked', 'stored']));
            $this->assertSame(['blocked', 'stored'], $GLOBALS['toggly_wordpress_cache_delete_calls']);
            $this->assertTrue($adapter->has('blocked'));
            $this->assertFalse($adapter->has('stored'));
        }

        #[RunInSeparateProcess]
        public function testLegacyAdapterReadsMultipleValuesAndClearsTransientRows(): void
        {
            $adapter = $this->legacyAdapter();
            $GLOBALS['toggly_wordpress_cache_values'] = ['present' => 'value'];

            $this->assertSame(['present' => 'value', 'missing' => 'fallback'], $adapter->getMultiple(['present', 'missing'], 'fallback'));
            $this->assertTrue($adapter->clear());
            $this->assertStringContainsString('_transient_%', $GLOBALS['wpdb']->queries[0]);
            $this->assertStringContainsString('_transient_timeout_%', $GLOBALS['wpdb']->queries[0]);
        }

        #[RunInSeparateProcess]
        public function testV3AdapterHandlesTransientDeletionFailuresAndClear(): void
        {
            $adapter = new WordPressCacheAdapterV3();
            $GLOBALS['toggly_wordpress_cache_values']['present'] = 'value';
            $GLOBALS['toggly_wordpress_cache_set_results']['blocked'] = false;
            $GLOBALS['toggly_wordpress_cache_delete_results']['blocked'] = false;
            $GLOBALS['toggly_wordpress_cache_values']['blocked'] = 'old-value';

            $this->assertSame('value', $adapter->get('present'));
            $this->assertSame('fallback', $adapter->get('missing', 'fallback'));
            $this->assertSame(
                ['present' => 'value', 'missing' => 'fallback'],
                $adapter->getMultiple(['present', 'missing'], 'fallback')
            );
            $this->assertTrue($adapter->has('present'));
            $this->assertFalse($adapter->setMultiple(['blocked' => 'value', 'stored' => 'value'], 30));
            $this->assertSame(['blocked', 'stored'], array_column($GLOBALS['toggly_wordpress_cache_adapter_calls'], 'key'));
            $this->assertTrue($adapter->has('stored'));
            $this->assertFalse($adapter->deleteMultiple(['blocked', 'stored']));
            $this->assertSame(['blocked', 'stored'], $GLOBALS['toggly_wordpress_cache_delete_calls']);
            $this->assertTrue($adapter->has('blocked'));
            $this->assertFalse($adapter->has('stored'));
            $this->assertTrue($adapter->clear());
            $this->assertCount(1, $GLOBALS['wpdb']->queries);
        }

        private function legacyAdapter(int $defaultTtl = 3600): object
        {
            require_once __DIR__ . '/../../../fixtures/LegacyPsrSimpleCacheInterface.php';

            return new \Toggly\WordPress\Storage\WordPressCacheAdapter($defaultTtl);
        }
    }

    final class RecordingWordPressDatabase
    {
        public string $options = 'wp_options';

        /** @var list<string> */
        public array $queries = [];

        public function query(string $query): int
        {
            $this->queries[] = $query;

            return 1;
        }
    }
}
