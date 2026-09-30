<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;

final class FeatureProviderRawDefinitionsExtractionTest extends TestCase
{
    public function testPreservesRawObjectDefinitionsWithNestedBracesAndEscapedQuotes(): void
    {
        $defs = <<<'JSON'
{"checkout":{"configurationValue":{"headline":"Use \"fast } checkout\"","details":{"locale":"en-US"}},"enabled":true}}
JSON;
        $body = '{"timestamp":7,"defs":' . $defs . ',"signature":"signature"}';

        $this->assertSame($defs, $this->extract($body));
    }

    public function testPreservesRawArrayDefinitionsWithEmbeddedBracketsAndEscapes(): void
    {
        $defs = <<<'JSON'
[{"featureKey":"checkout","configurationValue":"a ] bracket and \" quote"},{"featureKey":"banner","items":[1,{"key":"nested"}]}]
JSON;
        $body = '{"defs":' . $defs . ',"kid":"key-1","timestamp":9}';

        $this->assertSame($defs, $this->extract($body));
    }

    public function testMissingOrUnterminatedDefinitionsUseSafeEmptyFallbacks(): void
    {
        $this->assertSame('[]', $this->extract('{"features":[]}'));
        $this->assertSame('{}', $this->extract('{"defs":{"unfinished":true'));
        $this->assertSame('[]', $this->extract('{"defs":[1,2}'));
    }

    private function extract(string $body): string
    {
        $provider = new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'enable_live_updates' => false,
            ]),
            $this->createStub(TogglyHttpClient::class),
            $this->createStub(FeatureStateServiceInterface::class)
        );
        $method = new \ReflectionMethod($provider, 'extractSignedDefsFromBody');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke($provider, $body);
    }
}
