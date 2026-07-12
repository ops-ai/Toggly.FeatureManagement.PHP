<?php

namespace Toggly\FeatureManagement;

/**
 * SDK identity constants for HTTP User-Agent and WebSocket query parameters.
 */
final class SdkIdentity
{
    public const SDK_ID = 'php';
    public const SDK_VERSION = '0.2.0';

    private function __construct()
    {
    }

    public static function userAgent(): string
    {
        return 'toggly-' . self::SDK_ID . '/' . self::SDK_VERSION;
    }

    /**
     * @param array<string, string> $params
     */
    public static function buildQueryString(array $params = []): string
    {
        $params['sdk'] = self::SDK_ID;
        $params['sdkVersion'] = self::SDK_VERSION;

        return http_build_query($params);
    }
}
