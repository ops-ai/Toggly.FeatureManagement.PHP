<?php

namespace Toggly\FeatureManagement\Core;

/**
 * Maps common HTTP headers into EvalContext.request fields.
 *
 * Does not invent identity, groups, or claims — merge those separately.
 * Country header order: cf-ipcountry → x-vercel-ip-country → cloudfront-viewer-country.
 */
class HttpRequestMapper
{
    /**
     * Build a request context array from a header bag (case-insensitive keys).
     *
     * @param array<string, string>|null $headers
     * @return array{userAgent: ?string, acceptLanguage: ?string, country: ?string}
     */
    public static function fromHttpHeaders(?array $headers): array
    {
        if ($headers === null || $headers === []) {
            return [
                'userAgent' => null,
                'acceptLanguage' => null,
                'country' => null,
            ];
        }

        return [
            'userAgent' => self::header($headers, 'user-agent'),
            'acceptLanguage' => self::header($headers, 'accept-language'),
            'country' => self::firstPresent(
                $headers,
                'cf-ipcountry',
                'x-vercel-ip-country',
                'cloudfront-viewer-country'
            ),
        ];
    }

    /**
     * Merge HTTP-mapped request fields over an existing evaluation context array.
     * When headers are present, replaces context.request with the mapped request
     * (same behavior as other SDK HttpRequestMapper ports).
     *
     * @param array<string, string>|null $headers
     * @param array<string, mixed>|null $base
     * @return array<string, mixed>
     */
    public static function mergeIntoContext(?array $headers, ?array $base): array
    {
        $context = $base !== null ? $base : [];
        $context['request'] = self::fromHttpHeaders($headers);

        return $context;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if ($key !== null && strtolower((string)$key) === $lower && $value !== null && $value !== '') {
                return (string)$value;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function firstPresent(array $headers, string ...$names): ?string
    {
        foreach ($names as $name) {
            $value = self::header($headers, $name);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
