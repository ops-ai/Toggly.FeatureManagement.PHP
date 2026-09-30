<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Http\WebSocketClient;

final class FeatureProviderRefreshSchedulingTest extends TestCase
{
    public function testDrainsForcedRefreshQueuedDuringInFlightRefresh(): void
    {
        $calls = 0;
        $provider = null;
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->exactly(2))->method('get')->willReturnCallback(
            function () use (&$calls, &$provider): ResponseInterface {
                $calls++;
                if ($calls === 1) {
                    $provider->refreshFeatures(true);
                    return $this->jsonResponse('[{"featureKey":"checkout","filters":[{"name":"AlwaysOff"}]}]');
                }

                return $this->jsonResponse('[{"featureKey":"checkout","filters":[{"name":"AlwaysOn"}]}]');
            }
        );
        $http->method('getLastETag')->willReturn(null);

        $provider = $this->provider($http);
        $provider->refreshFeatures();

        $this->assertSame(2, $calls);
        $this->assertSame('AlwaysOn', $provider->getFeatureDefinition('checkout')->filters[0]->name);
    }

    public function testRefreshResetsAfterErrorAndContainsThrowingErrorCallback(): void
    {
        $events = [];
        $calls = 0;
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->exactly(2))->method('get')->willReturnCallback(
            function () use (&$calls): ResponseInterface {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('definitions unavailable');
                }

                return $this->jsonResponse('[{"featureKey":"recovered","filters":[{"name":"AlwaysOn"}]}]');
            }
        );
        $http->method('getLastETag')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Error refreshing features list', $this->anything());
        $logger->expects($this->once())->method('warning')->with('OnError callback threw', $this->anything());

        $provider = $this->provider(
            $http,
            $logger,
            function (string $message, ?\Throwable $error) use (&$events): void {
                $events[] = [$message, $error->getMessage()];
                throw new \RuntimeException('subscriber failed');
            }
        );
        $provider->refreshFeatures();
        $provider->refreshFeatures();

        $this->assertSame([['Error refreshing features list', 'definitions unavailable']], $events);
        $this->assertSame(2, $calls);
        $this->assertSame('AlwaysOn', $provider->getFeatureDefinition('recovered')->filters[0]->name);
    }

    public function testRefreshesWhenLiveFallbackIntervalHasElapsed(): void
    {
        $http = $this->createMock(TogglyHttpClient::class);
        $http->expects($this->once())->method('get')->with('definitions/app/Production')
            ->willReturn($this->jsonResponse('[{"featureKey":"scheduled","filters":[{"name":"AlwaysOn"}]}]'));
        $http->method('getLastETag')->willReturn(null);
        $provider = $this->provider($http);
        $webSocket = $this->createMock(WebSocketClient::class);
        $webSocket->method('isRunning')->willReturn(true);
        $this->setPrivate($provider, 'webSocketClient', $webSocket);
        $this->setPrivate($provider, 'lastFallbackPoll', time() - 1200);

        $provider->refreshFeatures();

        $this->assertSame('AlwaysOn', $provider->getFeatureDefinition('scheduled')->filters[0]->name);
        $this->assertGreaterThan(time() - 2, $this->getPrivate($provider, 'lastFallbackPoll'));
    }

    private function provider(TogglyHttpClient $http, ?LoggerInterface $logger = null, ?callable $onError = null): FeatureProvider
    {
        return new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'enable_live_updates' => false,
                'use_signed_definitions' => false,
                'on_error' => $onError,
            ]),
            $http,
            $this->createStub(FeatureStateServiceInterface::class),
            null,
            $logger
        );
    }

    private function setPrivate(object $object, string $property, $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $reflection->setValue($object, $value);
    }

    private function getPrivate(object $object, string $property)
    {
        $reflection = new \ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->getValue($object);
    }

    private function jsonResponse(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('rewind');
        $stream->method('getContents')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
