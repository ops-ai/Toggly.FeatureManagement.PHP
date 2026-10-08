<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Core\UserAgentParser;

final class UserAgentParserTest extends TestCase
{
    public function testEmptyUserAgentsDoNotInventSegmentAttributes(): void
    {
        $this->assertNull(UserAgentParser::parse(null));
        $this->assertNull(UserAgentParser::parse(''));
    }

    /**
     * @dataProvider classificationCases
     */
    public function testClassifiesBrowserOsAndAppleDeviceFamilies(string $userAgent, array $expected): void
    {
        $this->assertSame($expected, UserAgentParser::parse($userAgent));
    }

    public static function classificationCases(): array
    {
        return [
            'Edge wins over its Chromium token' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36 Edg/122.0.2365.92',
                ['browserFamily' => 'Edge', 'osFamily' => 'Windows', 'deviceFamily' => 'Other'],
            ],
            'Opera wins over its Chromium token' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/121.0.0.0 Mobile Safari/537.36 OPR/79.0.4195.76335',
                ['browserFamily' => 'Opera', 'osFamily' => 'Android', 'deviceFamily' => 'Other'],
            ],
            'iPhone Safari is distinct from Chromium' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_3 like Mac OS X) AppleWebKit/605.1.15 Version/17.3 Mobile/15E148 Safari/604.1',
                ['browserFamily' => 'Safari', 'osFamily' => 'iOS', 'deviceFamily' => 'iPhone'],
            ],
            'iPad Chrome uses the Apple mobile OS and device family' => [
                'Mozilla/5.0 (iPad; CPU OS 17_3 like Mac OS X) AppleWebKit/605.1.15 CriOS/121.0.6167.72 Mobile/15E148 Safari/604.1',
                ['browserFamily' => 'Chrome', 'osFamily' => 'iOS', 'deviceFamily' => 'iPad'],
            ],
            'iPod Firefox uses the Apple mobile OS and device family' => [
                'Mozilla/5.0 (iPod touch; CPU OS 15_7 like Mac OS X) AppleWebKit/605.1.15 FxiOS/121.0 Mobile/15E148 Safari/605.1.15',
                ['browserFamily' => 'Firefox', 'osFamily' => 'iOS', 'deviceFamily' => 'iPod'],
            ],
            'Mac Chromium is Chrome and not Safari' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/537.36 Chrome/121.0.0.0 Safari/537.36',
                ['browserFamily' => 'Chrome', 'osFamily' => 'Mac OS', 'deviceFamily' => 'Other'],
            ],
            'unknown desktop identifiers use explicit fallbacks' => [
                'CustomAgent/1.0 (Linux x86_64)',
                ['browserFamily' => 'Other', 'osFamily' => 'Linux', 'deviceFamily' => 'Other'],
            ],
            'unknown identifiers use generic OS and device fallbacks' => [
                'CustomAgent/1.0',
                ['browserFamily' => 'Other', 'osFamily' => 'Other', 'deviceFamily' => 'Other'],
            ],
            'Chromium masquerading as Safari stays Other' => [
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Version/17.0 Chromium/121.0 Safari/537.36',
                ['browserFamily' => 'Other', 'osFamily' => 'Linux', 'deviceFamily' => 'Other'],
            ],
        ];
    }
}
