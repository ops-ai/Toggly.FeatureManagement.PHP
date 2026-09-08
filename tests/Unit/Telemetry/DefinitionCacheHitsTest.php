<?php

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureSnapshotProviderInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Core\FeatureStateService;
use Toggly\FeatureManagement\Core\UsageStatsProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Http\WebSocketClient;
use Toggly\FeatureManagement\Models\FeatureDefinition;
use Toggly\FeatureManagement\Telemetry\UsageGrpcClient;

class DefinitionCacheHitsTest extends TestCase
{
    public function testNew200And304FlushFields(): void
    {
        $captured = null;
        $grpc = $this->capturingGrpc($captured);
        $usage = $this->usageProvider($grpc);
        $etag = null;
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('getLastETag')->willReturnCallback(static function () use (&$etag) {
            return $etag;
        });
        $http->method('setLastETag')->willReturnCallback(static function (?string $value) use (&$etag): void {
            $etag = $value;
        });
        $call = 0;
        $http->method('get')->willReturnCallback(function () use (&$etag, &$call) {
            $call++;
            if ($call === 1) {
                $etag = '"rev-1"';
                return $this->jsonResponse(
                    '[{"featureKey":"feature-a","filters":[{"name":"AlwaysOn"}]}]',
                    '"rev-1"'
                );
            }
            return null; // 304
        });
        $http->method('clearETag');

        $provider = $this->featureProvider($http, $usage);
        $provider->refreshFeatures(true);
        $provider->refreshFeatures(true);
        $usage->sendStats();

        $this->assertIsArray($captured);
        $this->assertSame(1, $captured['definitionCacheMisses']);
        $this->assertSame(1, $captured['definitionCacheHits']);
    }

    public function testSkippedPollWhileWebSocketLiveRecordsHit(): void
    {
        $captured = null;
        $grpc = $this->capturingGrpc($captured);
        $usage = $this->usageProvider($grpc);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('get');

        $provider = $this->featureProvider($http, $usage);
        $this->setPrivate($provider, 'webSocketClient', $this->runningWebSocket());
        $this->setPrivate($provider, 'lastFallbackPoll', time());

        $provider->refreshFeatures(false);
        $usage->sendStats();

        $this->assertIsArray($captured);
        $this->assertSame(1, $captured['definitionCacheHits']);
        $this->assertArrayNotHasKey('definitionCacheMisses', $captured);
    }

    public function testWebSocketForceBypassesPollSkipAndRecordsMissOnNewRevision(): void
    {
        $captured = null;
        $grpc = $this->capturingGrpc($captured);
        $usage = $this->usageProvider($grpc);

        $etag = '"rev-1"';
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('getLastETag')->willReturnCallback(static function () use (&$etag) {
            return $etag;
        });
        $http->method('setLastETag')->willReturnCallback(static function (?string $value) use (&$etag): void {
            $etag = $value;
        });
        $http->expects($this->once())->method('get')->willReturnCallback(function () use (&$etag) {
            $etag = '"rev-2"';
            return $this->jsonResponse(
                '[{"featureKey":"feature-b","filters":[{"name":"AlwaysOn"}]}]',
                '"rev-2"'
            );
        });
        $http->method('clearETag');

        $provider = $this->featureProvider($http, $usage);
        $this->setPrivate($provider, 'webSocketClient', $this->runningWebSocket());
        $this->setPrivate($provider, 'lastFallbackPoll', time());
        $this->setPrivate($provider, 'loaded', true);
        $this->setPrivate($provider, 'definitions', [
            'feature-a' => new FeatureDefinition([
                'featureKey' => 'feature-a',
                'filters' => [['name' => 'AlwaysOn']],
            ]),
        ]);

        // force=true must not use scheduled-poll skip
        $provider->refreshFeatures(true);
        $usage->sendStats();

        $this->assertIsArray($captured);
        $this->assertSame(1, $captured['definitionCacheMisses']);
        $this->assertArrayNotHasKey('definitionCacheHits', $captured);
        $this->assertNotNull($provider->getFeatureDefinition('feature-b'));
    }

    public function testConcurrentRefreshDoesNotCount(): void
    {
        $captured = null;
        $usage = $this->usageProvider($this->capturingGrpc($captured));
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->never())->method('get');

        $provider = $this->featureProvider($http, $usage);
        $this->setPrivate($provider, 'refreshInProgress', true);
        $provider->refreshFeatures(false);
        $provider->refreshFeatures(true); // queues pending WS; no count on the skip itself

        $peek = $usage->peekPayload();
        $this->assertNull($peek);
    }

    public function testMatchingEtagOn200RecordsHit(): void
    {
        $captured = null;
        $grpc = $this->capturingGrpc($captured);
        $usage = $this->usageProvider($grpc);
        $etag = '"rev-1"';
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('getLastETag')->willReturnCallback(static function () use (&$etag) {
            return $etag;
        });
        $http->expects($this->once())->method('get')->willReturnCallback(function () {
            return $this->jsonResponse(
                '[{"featureKey":"feature-a","filters":[{"name":"AlwaysOn"}]}]',
                '"rev-1"'
            );
        });
        $http->method('clearETag');

        $provider = $this->featureProvider($http, $usage);
        $this->setPrivate($provider, 'loaded', true);
        $provider->refreshFeatures(true);
        $usage->sendStats();

        $this->assertIsArray($captured);
        $this->assertSame(1, $captured['definitionCacheHits']);
        $this->assertArrayNotHasKey('definitionCacheMisses', $captured);
    }

    public function testNetworkErrorKeepingLastGoodRecordsHit(): void
    {
        $captured = null;
        $grpc = $this->capturingGrpc($captured);
        $usage = $this->usageProvider($grpc);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('getLastETag')->willReturn('"rev-1"');
        $http->expects($this->once())->method('get')->willThrowException(new \RuntimeException('network down'));
        $http->method('clearETag');

        $provider = $this->featureProvider($http, $usage);
        $this->setPrivate($provider, 'loaded', true);
        $this->setPrivate($provider, 'definitions', [
            'feature-a' => new FeatureDefinition([
                'featureKey' => 'feature-a',
                'filters' => [['name' => 'AlwaysOn']],
            ]),
        ]);
        $provider->refreshFeatures(true);
        $usage->sendStats();

        $this->assertIsArray($captured);
        $this->assertSame(1, $captured['definitionCacheHits']);
        $this->assertNotNull($provider->getFeatureDefinition('feature-a'));
    }

    public function testStartupSnapshotRecordsHit(): void
    {
        $captured = null;
        $grpc = $this->capturingGrpc($captured);
        $usage = $this->usageProvider($grpc);
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('setLastETag');
        $http->expects($this->never())->method('get');

        $snapshot = $this->createMock(FeatureSnapshotProviderInterface::class);
        $snapshot->method('getFeaturesSnapshot')->willReturn([
            'features' => [
                new FeatureDefinition([
                    'featureKey' => 'snap-a',
                    'filters' => [['name' => 'AlwaysOn']],
                ]),
            ],
            'signature' => null,
            'keyId' => null,
            'timestamp' => null,
            'etag' => '"snap-1"',
            'signedDefsJson' => null,
        ]);

        $provider = new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'base_url' => 'https://definitions.example/',
                'enable_live_updates' => false,
            ]),
            $http,
            new FeatureStateService(),
            $snapshot,
            new NullLogger(),
            $usage
        );

        $usage->sendStats();
        $this->assertIsArray($captured);
        $this->assertSame(1, $captured['definitionCacheHits']);
        $this->assertNotNull($provider->getFeatureDefinition('snap-a'));
    }

    /**
     * @param array<string, mixed>|null $captured
     */
    private function capturingGrpc(&$captured): UsageGrpcClient
    {
        return new class($captured) implements UsageGrpcClient {
            /** @var array<string, mixed>|null */
            public $captured;

            public function __construct(&$captured)
            {
                $this->captured = &$captured;
            }

            public function sendStats(array $payload, array $metadata = []): void
            {
                $this->captured = $payload;
            }

            public function close(): void
            {
            }
        };
    }

    private function usageProvider(UsageGrpcClient $grpc): UsageStatsProvider
    {
        $http = $this->createMock(TogglyHttpClient::class);
        return new UsageStatsProvider(
            new TogglySettings(['app_key' => 'app', 'environment' => 'Production', 'instance_name' => 't1']),
            $http,
            null,
            new NullLogger(),
            $grpc
        );
    }

    private function featureProvider(TogglyHttpClient $http, UsageStatsProvider $usage): FeatureProvider
    {
        return new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'base_url' => 'https://definitions.example/',
                'enable_live_updates' => false,
            ]),
            $http,
            new FeatureStateService(),
            null,
            new NullLogger(),
            $usage
        );
    }

    private function runningWebSocket(): WebSocketClient
    {
        $ws = $this->createMock(WebSocketClient::class);
        $ws->method('isRunning')->willReturn(true);
        $ws->method('isAvailable')->willReturn(true);
        return $ws;
    }

    /**
     * @param mixed $value
     */
    private function setPrivate(object $object, string $property, $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    private function jsonResponse(string $body, string $etag): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('rewind');
        $stream->method('getContents')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaderLine')->willReturnCallback(static function (string $name) use ($etag) {
            return strcasecmp($name, 'ETag') === 0 ? $etag : '';
        });

        return $response;
    }
}
