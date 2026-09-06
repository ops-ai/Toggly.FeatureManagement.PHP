<?php

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Telemetry\IdentityHasher;

class IdentityHasherTest extends TestCase
{
    public function testFnv1aUtf8SignedInt32MatchesGoNodePython(): void
    {
        $alice = IdentityHasher::hashIdentity('alice');
        $this->assertIsInt($alice);
        $this->assertSame($alice, IdentityHasher::hashIdentity('alice'));
        $this->assertNotSame($alice, IdentityHasher::hashIdentity('bob'));

        // Go hash/fnv New32a on []byte(s), cast to int32 — fixtures from sibling SDKs.
        $this->assertSame(-2027809817, $alice);
        $this->assertSame(-1473556407, IdentityHasher::hashIdentity('café'));
        $this->assertSame(2141686490, IdentityHasher::hashIdentity('🚀'));
    }

    public function testCafeIsNotUtf16CodeUnitHash(): void
    {
        $value = 'café';
        $utf16Style = 2166136261;
        $len = mb_strlen($value, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($value, $i, 1, 'UTF-8');
            $code = $this->utf16CodeUnit($ch);
            $utf16Style ^= $code;
            $utf16Style = ($utf16Style * 16777619) & 0xFFFFFFFF;
        }
        $utf16Signed = $utf16Style > 0x7FFFFFFF ? $utf16Style - 0x100000000 : $utf16Style;

        $this->assertNotSame($utf16Signed, IdentityHasher::hashIdentity($value));
    }

    private function utf16CodeUnit(string $char): int
    {
        // Approximate JS charCodeAt for BMP characters (café's é is BMP).
        $utf16 = mb_convert_encoding($char, 'UTF-16BE', 'UTF-8');
        $bytes = unpack('n', substr($utf16, 0, 2));

        return (int) $bytes[1];
    }
}
