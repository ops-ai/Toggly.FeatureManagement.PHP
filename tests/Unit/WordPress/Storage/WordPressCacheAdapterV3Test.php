<?php

namespace {
if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['toggly_wordpress_cache_adapter_calls'][] = [
            'key' => $key,
            'value' => $value,
            'expiration' => $expiration,
        ];

        return true;
    }
}
}

namespace Toggly\FeatureManagement\Tests\Unit\WordPress\Storage {

use DateInterval;
use PHPUnit\Framework\TestCase;
use Toggly\WordPress\Storage\WordPressCacheAdapterV3;

class WordPressCacheAdapterV3Test extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['toggly_wordpress_cache_adapter_calls'] = [];
    }

    public function testSetConvertsDateIntervalToTransientExpirationSeconds(): void
    {
        $adapter = new WordPressCacheAdapterV3();

        $adapter->set('definition', 'value', new DateInterval('PT90S'));

        $this->assertSame(90, $GLOBALS['toggly_wordpress_cache_adapter_calls'][0]['expiration']);
    }

    public function testSetMultipleConvertsDateIntervalForEveryTransient(): void
    {
        $adapter = new WordPressCacheAdapterV3();

        $adapter->setMultiple(['first' => 'one', 'second' => 'two'], new DateInterval('PT2M'));

        $this->assertSame([120, 120], array_column($GLOBALS['toggly_wordpress_cache_adapter_calls'], 'expiration'));
    }

    public function testSetKeepsIntegerAndDefaultTtls(): void
    {
        $adapter = new WordPressCacheAdapterV3(600);

        $adapter->set('integer', 'value', 45);
        $adapter->set('default', 'value');

        $this->assertSame([45, 600], array_column($GLOBALS['toggly_wordpress_cache_adapter_calls'], 'expiration'));
    }
}
}
