<?php

namespace Toggly\FeatureManagement\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\FeatureManagement\SdkIdentity;

/**
 * Guards the User-Agent default. A static call cannot be a parameter default,
 * so expressing it that way makes the whole class fail to compile.
 */
class TogglyHttpClientTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sentHeaders = [];

    public function testOmittedUserAgentFallsBackToSdkIdentity(): void
    {
        $client = new TogglyHttpClient($this->httpClient(), $this->requestFactory(), 'https://example.test/');

        $client->get('definitions');

        $this->assertSame(SdkIdentity::userAgent(), $this->sentHeaders['User-Agent']);
    }

    public function testExplicitUserAgentIsUsed(): void
    {
        $client = new TogglyHttpClient(
            $this->httpClient(),
            $this->requestFactory(),
            'https://example.test/',
            'custom-agent/1.2.3'
        );

        $client->get('definitions');

        $this->assertSame('custom-agent/1.2.3', $this->sentHeaders['User-Agent']);
    }

    private function requestFactory(): RequestFactoryInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(
            function (string $name, $value) use (&$request): RequestInterface {
                $this->sentHeaders[$name] = $value;
                return $request;
            }
        );

        $factory = $this->createMock(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturn($request);

        return $factory;
    }

    private function httpClient(): ClientInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaderLine')->willReturn('');

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        return $client;
    }
}
