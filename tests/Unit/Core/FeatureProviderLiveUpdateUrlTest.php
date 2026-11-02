<?php

namespace Toggly\FeatureManagement\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureStateServiceInterface;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\Http\WebSocketClient;
use Toggly\FeatureManagement\SdkIdentity;

final class FeatureProviderLiveUpdateUrlTest extends TestCase
{
    public function testHttpsBaseUrlConnectsOverWssWithRevisionQuery(): void
    {
        $this->assertLiveUpdateConnectUrl(
            'https://cdn.example.test/base/',
            '"rev-https"',
            'wss://cdn.example.test/base/app/ws?' . SdkIdentity::buildQueryString(['rev' => '"rev-https"'])
        );
    }

    public function testHttpBaseUrlConnectsOverWsWithoutRevisionWhenEtagMissing(): void
    {
        $this->assertLiveUpdateConnectUrl(
            'http://cdn.example.test/base',
            null,
            'ws://cdn.example.test/base/app/ws?' . SdkIdentity::buildQueryString([])
        );
    }

    private function assertLiveUpdateConnectUrl(string $baseUrl, ?string $etag, string $expectedUrl): void
    {
        $connectedUrl = null;
        $http = $this->createMock(TogglyHttpClient::class);
        $http->method('get')->willReturn($this->jsonResponse(
            '[{"featureKey":"live","filters":[{"name":"AlwaysOn"}]}]'
        ));
        $http->method('getLastETag')->willReturn($etag);

        $webSocket = $this->createMock(WebSocketClient::class);
        $webSocket->method('isAvailable')->willReturn(true);
        $webSocket->method('isRunning')->willReturn(false);
        $webSocket->expects($this->once())
            ->method('connect')
            ->with(
                $expectedUrl,
                $this->callback(static function ($callback): bool {
                    return is_callable($callback);
                })
            )
            ->willReturnCallback(static function (string $url) use (&$connectedUrl): bool {
                $connectedUrl = $url;

                return true;
            });

        $provider = new FeatureProvider(
            new TogglySettings([
                'app_key' => 'app',
                'environment' => 'Production',
                'base_url' => $baseUrl,
                'enable_live_updates' => true,
                'use_signed_definitions' => false,
            ]),
            $http,
            $this->createStub(FeatureStateServiceInterface::class)
        );
        $this->setPrivate($provider, 'webSocketClient', $webSocket);

        $provider->refreshFeatures(true);

        $this->assertSame($expectedUrl, $connectedUrl);
        $this->assertSame('AlwaysOn', $provider->getFeatureDefinition('live')->filters[0]->name);
    }

    private function setPrivate(object $object, string $property, $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $reflection->setValue($object, $value);
    }

    private function jsonResponse(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeader')->willReturn([]);

        return $response;
    }
}
