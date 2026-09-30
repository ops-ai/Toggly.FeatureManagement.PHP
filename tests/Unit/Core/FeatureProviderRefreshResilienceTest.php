<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Contracts\UsageStatsProviderInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;

final class FeatureProviderRefreshResilienceTest extends TestCase
{
    public function testInvalidDefinitionResponseKeepsLastKnownGoodAndRecordsCacheHit(): void
    {
        $usage = new class implements UsageStatsProviderInterface {
            /** @var string[] */
            public array $definitionOutcomes = [];

            public function recordCheck(string $featureKey, bool $allowed): void {}
            public function recordUsageWithContext(string $featureKey, $context, bool $allowed): void {}
            public function recordUsage(string $featureKey): void {}
            public function recordView(string $featureKey): void {}
            public function recordDefinitionCacheHit(): void { $this->definitionOutcomes[] = 'hit'; }
            public function recordDefinitionCacheMiss(): void { $this->definitionOutcomes[] = 'miss'; }
        };
        $http = $this->http(
            '[{"featureKey":"checkout","filters":[{"name":"AlwaysOn"}]}]',
            '{not-valid-json'
        );
        $provider = $this->provider($http, $usage);

        $provider->refreshFeatures();
        $knownGood = $provider->getFeatureDefinition('checkout');
        $provider->refreshFeatures();

        $this->assertNotNull($knownGood);
        $this->assertSame($knownGood, $provider->getFeatureDefinition('checkout'));
        $this->assertSame('AlwaysOn', $knownGood->filters[0]->name);
        $this->assertSame(['miss', 'hit'], $usage->definitionOutcomes);
        $this->assertNull($provider->getDebugInfo()['last_error']);
    }

    public function testThrowingCacheTelemetryDoesNotPreventApplyOrNotModifiedHandling(): void
    {
        $usage = new class implements UsageStatsProviderInterface {
            public function recordCheck(string $featureKey, bool $allowed): void {}
            public function recordUsageWithContext(string $featureKey, $context, bool $allowed): void {}
            public function recordUsage(string $featureKey): void {}
            public function recordView(string $featureKey): void {}
            public function recordDefinitionCacheHit(): void { throw new \RuntimeException('hit observer unavailable'); }
            public function recordDefinitionCacheMiss(): void { throw new \RuntimeException('miss observer unavailable'); }
        };
        $logger = new class extends NullLogger {
            /** @var string[] */
            public array $debugMessages = [];

            public function debug($message, array $context = []): void
            {
                $this->debugMessages[] = (string) $message;
            }
        };
        $http = $this->http('[{"featureKey":"payments","filters":[{"name":"AlwaysOn"}]}]', null);
        $provider = $this->provider($http, $usage, $logger);

        $provider->refreshFeatures();
        $provider->refreshFeatures();

        $this->assertSame('AlwaysOn', $provider->getFeatureDefinition('payments')->filters[0]->name);
        $this->assertContains('Failed to record definition cache miss', $logger->debugMessages);
        $this->assertContains('Features not modified (304)', $logger->debugMessages);
        $this->assertContains('Failed to record definition cache hit', $logger->debugMessages);
        $this->assertNull($provider->getDebugInfo()['last_error']);
    }

    private function provider(
        TogglyHttpClient $http,
        UsageStatsProviderInterface $usage,
        ?NullLogger $logger = null
    ): FeatureProvider {
        return new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'enable_live_updates' => false,
                'use_signed_definitions' => false,
            ]),
            $http,
            $this->createStub(FeatureStateServiceInterface::class),
            null,
            $logger,
            $usage
        );
    }

    private function http(?string ...$bodies): TogglyHttpClient
    {
        $responses = array_map(function (?string $body) {
            return $body === null ? null : $this->response($body);
        }, $bodies);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->exactly(count($responses)))->method('get')->with('definitions/app/Production')
            ->willReturnOnConsecutiveCalls(...$responses);
        $http->method('getLastETag')->willReturn(null);

        return $http;
    }

    private function response(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('rewind');
        $stream->method('getContents')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
