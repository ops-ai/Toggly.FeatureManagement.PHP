<?php

namespace Toggly\FeatureManagement\Http {
    function sleep(int $seconds): int
    {
        $GLOBALS['toggly_http_client_sleep_calls'][] = $seconds;

        return 0;
    }
}

namespace Toggly\FeatureManagement\Tests\Unit\Http {

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Toggly\FeatureManagement\Http\TogglyHttpClient;

class TogglyHttpClientCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['toggly_http_client_sleep_calls'] = [];
    }

    public function testGetStoresEtagAndSendsItOnTheNextNotModifiedRequest(): void
    {
        $requests = [];
        $factory = $this->recordingRequestFactory($requests);
        $first = $this->response(200, '"revision-17"');
        $notModified = $this->response(304);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($first, $notModified);
        $client = new TogglyHttpClient($transport, $factory, 'https://definitions.example.test');

        $this->assertSame($first, $client->get('/api/definitions'));
        $this->assertSame('"revision-17"', $client->getLastETag());
        $this->assertNull($client->get('api/definitions'));

        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('https://definitions.example.test/api/definitions', $requests[0]['uri']);
        $this->assertSame('application/json', $requests[0]['headers']['Accept']);
        $this->assertArrayNotHasKey('If-None-Match', $requests[0]['headers']);
        $this->assertSame('"revision-17"', $requests[1]['headers']['If-None-Match']);
    }

    public function testPostSerializesPayloadAndReturnsSuccessfulTransportResponse(): void
    {
        $requests = [];
        $factory = $this->recordingRequestFactory($requests);
        $response = $this->response(201);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects($this->once())
            ->method('sendRequest')
            ->willReturn($response);
        $client = new TogglyHttpClient(
            $transport,
            $factory,
            'https://metrics.example.test/',
            'coverage-client/1.0'
        );

        $this->assertSame($response, $client->post('/api/metrics', ['count' => 2], 1));

        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('https://metrics.example.test/api/metrics', $requests[0]['uri']);
        $this->assertSame('coverage-client/1.0', $requests[0]['headers']['User-Agent']);
        $this->assertSame('application/json', $requests[0]['headers']['Content-Type']);
        $this->assertSame('application/json', $requests[0]['headers']['Accept']);
        $this->assertSame('{"count":2}', $requests[0]['body']);
    }

    public function testSetAndClearEtagControlConditionalRequests(): void
    {
        $requests = [];
        $factory = $this->recordingRequestFactory($requests);
        $notModified = $this->response(304);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturn($notModified);
        $client = new TogglyHttpClient($transport, $factory, 'https://definitions.example.test');

        $client->setLastETag('"persisted"');
        $this->assertNull($client->get('api/definitions'));
        $client->clearETag();
        $this->assertNull($client->get('api/definitions'));

        $this->assertSame('"persisted"', $requests[0]['headers']['If-None-Match']);
        $this->assertArrayNotHasKey('If-None-Match', $requests[1]['headers']);
        $this->assertNull($client->getLastETag());
    }

    public function testGetRetriesNotFoundBeforeReturningARecoveredResponse(): void
    {
        $requests = [];
        $factory = $this->recordingRequestFactory($requests);
        $recovered = $this->response(200);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($this->response(404), $recovered);
        $client = new TogglyHttpClient($transport, $factory, 'https://definitions.example.test');

        $this->assertSame($recovered, $client->get('api/definitions'));
        $this->assertSame([2], $this->sleepCalls());
    }

    public function testGetRaisesAfterAllTransientFailuresAndAppliesExponentialBackoff(): void
    {
        $requests = [];
        $factory = $this->recordingRequestFactory($requests);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects($this->exactly(8))
            ->method('sendRequest')
            ->willReturn($this->response(500, '', 'gateway unavailable'));
        $client = new TogglyHttpClient($transport, $factory, 'https://definitions.example.test');

        try {
            $client->get('api/definitions');
            $this->fail('Expected GET retries to end in a transport exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(500, $exception->getCode());
            $this->assertStringContainsString('gateway unavailable', $exception->getMessage());
        }

        $this->assertSame([2, 4, 8, 16, 32, 64, 128], $this->sleepCalls());
    }

    public function testPostRetriesARecoverableFailureBeforeReturningSuccess(): void
    {
        $requests = [];
        $factory = $this->recordingRequestFactory($requests);
        $success = $this->response(202);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($this->response(503), $success);
        $client = new TogglyHttpClient($transport, $factory, 'https://metrics.example.test');

        $this->assertSame($success, $client->post('api/metrics', '{"count":2}', 2));
        $this->assertSame([2], $this->sleepCalls());
    }

    /**
     * @param array<int, array{method: string, uri: string, headers: array<string, string>, body: string}> $requests
     */
    private function recordingRequestFactory(array &$requests): RequestFactoryInterface
    {
        $factory = $this->createMock(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturnCallback(function (string $method, string $uri) use (&$requests): RequestInterface {
            $index = count($requests);
            $requests[$index] = [
                'method' => $method,
                'uri' => $uri,
                'headers' => [],
                'body' => '',
            ];
            $stream = $this->createMock(StreamInterface::class);
            $stream->method('write')->willReturnCallback(function (string $body) use (&$requests, $index): int {
                $requests[$index]['body'] = $body;

                return strlen($body);
            });
            $request = $this->createMock(RequestInterface::class);
            $request->method('withHeader')->willReturnCallback(
                function (string $name, string $value) use (&$requests, $index, $request): RequestInterface {
                    $requests[$index]['headers'][$name] = $value;

                    return $request;
                }
            );
            $request->method('getBody')->willReturn($stream);

            return $request;
        });

        return $factory;
    }

    private function response(int $status, string $etag = '', string $bodyContents = ''): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getHeaderLine')->with('ETag')->willReturn($etag);
        $body = $this->createMock(StreamInterface::class);
        $body->method('getContents')->willReturn($bodyContents);
        $response->method('getBody')->willReturn($body);

        return $response;
    }

    /**
     * @return int[]
     */
    private function sleepCalls(): array
    {
        return $GLOBALS['toggly_http_client_sleep_calls'];
    }
}
}
