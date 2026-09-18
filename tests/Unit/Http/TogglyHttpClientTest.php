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

    public function testWithBaseUrlPreservesTransportAndChangesHost(): void
    {
        $client = new TogglyHttpClient($this->httpClient(), $this->requestFactory(), 'https://definitions.toggly.io/');
        $this->assertSame('https://definitions.toggly.io/', $client->getBaseUrl());

        $rebased = $client->withBaseUrl('https://app.toggly.io/');
        $this->assertSame('https://app.toggly.io/', $rebased->getBaseUrl());
        $this->assertSame('https://definitions.toggly.io/', $client->getBaseUrl());
    }

    public function testPostWithSingleAttemptDoesNotSleepOnFailure(): void
    {
        $uris = [];
        $client = new TogglyHttpClient(
            $this->httpClient(404),
            $this->requestFactory($uris),
            'https://definitions.toggly.io/'
        );

        $started = microtime(true);
        try {
            $client->post('api/usage/stats', ['appKey' => 'x'], 1);
            $this->fail('Expected POST failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('404', $e->getMessage());
        }
        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertSame(['https://definitions.toggly.io/api/usage/stats'], $uris);
    }

    /**
     * @param list<string>|null $uris
     */
    private function requestFactory(?array &$uris = null): RequestFactoryInterface
    {
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('write')->willReturn(1);

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(
            function (string $name, $value) use (&$request): RequestInterface {
                $this->sentHeaders[$name] = $value;
                return $request;
            }
        );
        $request->method('getBody')->willReturn($stream);

        $factory = $this->createMock(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturnCallback(
            function (string $method, $uri) use ($request, &$uris): RequestInterface {
                unset($method);
                if ($uris !== null) {
                    $uris[] = (string) $uri;
                }

                return $request;
            }
        );

        return $factory;
    }

    private function httpClient(int $status = 200): ClientInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getHeaderLine')->willReturn('');
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->method('rewind');
        $body->method('getContents')->willReturn('');
        $response->method('getBody')->willReturn($body);

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        return $client;
    }
}
