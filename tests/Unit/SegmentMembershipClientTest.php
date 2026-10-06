<?php

namespace Toggly\FeatureManagement\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Toggly\FeatureManagement\Exceptions\TogglyException;
use Toggly\FeatureManagement\SegmentMembershipClient;

class SegmentMembershipClientTest extends TestCase
{
    /** @var list<array{0:string,1:string}> */
    private array $created = [];

    /** @var array<string, string> */
    private array $headers = [];

    public function testListAddRemoveReplaceSendBackendKeyAndDecodeJson(): void
    {
        $client = $this->client(200, '{"ok":true,"identifiers":["a"]}');

        $this->assertSame(['ok' => true, 'identifiers' => ['a']], $client->listSegments());
        $this->assertSame(['ok' => true, 'identifiers' => ['a']], $client->addSegmentMembers('Beta Testers', ['a']));
        $this->assertSame(['ok' => true, 'identifiers' => ['a']], $client->removeSegmentMembers('Beta Testers', ['a']));
        $this->assertSame(['ok' => true, 'identifiers' => ['a']], $client->replaceSegmentMembers('Beta Testers', ['a']));

        $this->assertSame('backend-key', $this->headers['Authorization']);
        $this->assertSame(
            [
                ['GET', 'https://app.toggly.io/api/v2/segments'],
                ['POST', 'https://app.toggly.io/api/v2/segments/Beta%20Testers/items'],
                ['DELETE', 'https://app.toggly.io/api/v2/segments/Beta%20Testers/items'],
                ['PUT', 'https://app.toggly.io/api/v2/segments/Beta%20Testers/items'],
            ],
            $this->created
        );
    }

    public function testHttpErrorThrows(): void
    {
        $client = $this->client(403, '{}');
        $this->expectException(TogglyException::class);
        $this->expectExceptionMessage('403');
        $client->listSegments();
    }

    public function testInvalidJsonBodyThrows(): void
    {
        $client = $this->client(200, 'not-json');
        $this->expectException(TogglyException::class);
        $this->expectExceptionMessage('invalid JSON');
        $client->listSegments();
    }

    public function testNonArrayJsonBodyThrows(): void
    {
        $client = $this->client(200, 'null');
        $this->expectException(TogglyException::class);
        $this->expectExceptionMessage('non-array');
        $client->listSegments();
    }

    public function testEmptySuccessBodyReturnsEmptyArray(): void
    {
        $client = $this->client(204, '');
        $this->assertSame([], $client->removeSegmentMembers('Beta Testers', ['a']));
    }

    public function testWhitespaceOnlySuccessBodyReturnsEmptyArray(): void
    {
        $client = $this->client(200, "  \n");
        $this->assertSame([], $client->replaceSegmentMembers('Beta Testers', ['a']));
    }

    private function client(int $status, string $body): SegmentMembershipClient
    {
        return new SegmentMembershipClient(
            $this->http($status, $body),
            $this->requests(),
            $this->streams(),
            'backend-key'
        );
    }

    private function requests(): RequestFactoryInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(
            function (string $name, $value) use (&$request): RequestInterface {
                $this->headers[$name] = (string) $value;
                return $request;
            }
        );
        $request->method('withBody')->willReturn($request);

        $factory = $this->createMock(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturnCallback(
            function (string $method, $uri) use ($request): RequestInterface {
                $this->created[] = [$method, (string) $uri];
                return $request;
            }
        );

        return $factory;
    }

    private function streams(): StreamFactoryInterface
    {
        $factory = $this->createMock(StreamFactoryInterface::class);
        $factory->method('createStream')->willReturn($this->createMock(StreamInterface::class));
        return $factory;
    }

    private function http(int $status, string $payload): ClientInterface
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn($payload);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturn($response);
        return $http;
    }
}
