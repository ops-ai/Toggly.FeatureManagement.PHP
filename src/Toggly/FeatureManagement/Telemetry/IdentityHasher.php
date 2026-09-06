<?php

namespace Toggly\FeatureManagement\Telemetry;

/**
 * UTF-8 FNV-1a 32-bit identity hashing as signed int32.
 *
 * Matches Go {@code hash/fnv} New32a on {@code []byte(s)}, Node {@code hashIdentity},
 * and Python {@code hash_identity}.
 */
final class IdentityHasher
{
    private const FNV_OFFSET = 2166136261;
    private const FNV_PRIME = 16777619;

    private function __construct()
    {
    }

    /**
     * Hash an identity string to a signed 32-bit FNV-1a value.
     */
    public static function hashIdentity(string $identity): int
    {
        $hash = self::FNV_OFFSET;
        $bytes = $identity;
        // PHP strings are byte sequences; treat as UTF-8 octets (same as Go []byte).
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $hash ^= ord($bytes[$i]);
            $hash = ($hash * self::FNV_PRIME) & 0xFFFFFFFF;
        }

        if ($hash > 0x7FFFFFFF) {
            return $hash - 0x100000000;
        }

        return $hash;
    }
}
