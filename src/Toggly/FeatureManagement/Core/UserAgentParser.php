<?php

namespace Toggly\FeatureManagement\Core;

/**
 * Best-effort User-Agent parse for segment filters (parity with toggly-eval / other SDKs).
 * Bit-identical UA trees across languages are not required.
 */
class UserAgentParser
{
    /**
     * @return array{browserFamily: string, osFamily: string, deviceFamily: string}|null
     */
    public static function parse(?string $userAgent): ?array
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return [
            'browserFamily' => self::detectBrowser($userAgent),
            'osFamily' => self::detectOs($userAgent),
            'deviceFamily' => self::detectDevice($userAgent),
        ];
    }

    private static function detectBrowser(string $ua): string
    {
        if (strpos($ua, 'Edg/') !== false || strpos($ua, 'EdgiOS/') !== false) {
            return 'Edge';
        }
        if (strpos($ua, 'OPR/') !== false || strpos($ua, 'Opera') !== false) {
            return 'Opera';
        }
        if (strpos($ua, 'Chrome/') !== false || strpos($ua, 'CriOS/') !== false) {
            return 'Chrome';
        }
        if (strpos($ua, 'Firefox/') !== false || strpos($ua, 'FxiOS/') !== false) {
            return 'Firefox';
        }
        if (
            strpos($ua, 'Safari/') !== false
            && strpos($ua, 'Version/') !== false
            && strpos($ua, 'Chrome') === false
            && strpos($ua, 'Chromium') === false
        ) {
            return 'Safari';
        }

        return 'Other';
    }

    private static function detectOs(string $ua): string
    {
        if (strpos($ua, 'Android') !== false) {
            return 'Android';
        }
        if (
            strpos($ua, 'iPhone') !== false
            || strpos($ua, 'iPad') !== false
            || strpos($ua, 'iPod') !== false
            || strpos($ua, 'CPU iPhone OS') !== false
            || strpos($ua, 'CPU OS') !== false
        ) {
            return 'iOS';
        }
        if (strpos($ua, 'Mac OS X') !== false || strpos($ua, 'Macintosh') !== false) {
            return 'Mac OS';
        }
        if (strpos($ua, 'Windows') !== false) {
            return 'Windows';
        }
        if (strpos($ua, 'Linux') !== false) {
            return 'Linux';
        }

        return 'Other';
    }

    private static function detectDevice(string $ua): string
    {
        if (strpos($ua, 'iPhone') !== false) {
            return 'iPhone';
        }
        if (strpos($ua, 'iPad') !== false) {
            return 'iPad';
        }
        if (strpos($ua, 'iPod') !== false) {
            return 'iPod';
        }

        return 'Other';
    }
}
