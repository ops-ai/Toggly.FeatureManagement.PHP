<?php

namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(private string $message)
            {
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

}

namespace Toggly\WordPress\Http {
    function wp_get_current_user(): mixed
    {
        return $GLOBALS['toggly_wordpress_current_user'] ?? null;
    }

    function wp_remote_request(string $url, array $args): mixed
    {
        $GLOBALS['toggly_wordpress_http_request'] = compact('url', 'args');

        return $GLOBALS['toggly_wordpress_http_response'];
    }

    function is_wp_error(mixed $response): bool
    {
        return $response instanceof \WP_Error;
    }

    function wp_remote_retrieve_response_code(array $response): int
    {
        return $response['response']['code'];
    }

    function wp_remote_retrieve_response_message(array $response): string
    {
        return $response['response']['message'];
    }

    function wp_remote_retrieve_body(array $response): string
    {
        return $response['body'];
    }

    function wp_remote_retrieve_headers(array $response): array
    {
        return $response['headers'];
    }
}

namespace Toggly\FeatureManagement\Tests\Unit\WordPress\Http {
    use PHPUnit\Framework\TestCase;
    use Toggly\WordPress\Http\WordPressFeatureContextProvider;
    use Toggly\WordPress\Http\WordPressHttpClient;
    use Toggly\WordPress\Http\WordPressHttpClientException;
    use Toggly\WordPress\Http\WordPressRequest;
    use Toggly\WordPress\Http\WordPressRequestFactory;
    use Toggly\WordPress\Http\WordPressResponse;
    use Toggly\WordPress\Http\WordPressStream;
    use Toggly\WordPress\Http\WordPressUri;

    final class WordPressHttpPrimitivesTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['toggly_wordpress_current_user'] = null;
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
            $GLOBALS['toggly_wordpress_http_response'] = [
                'response' => ['code' => 201, 'message' => 'Created'],
                'headers' => ['X-Request-Id' => 'request-1'],
                'body' => 'created',
            ];
        }

        public function testContextProviderTracksFeatureAccessAndSelectsTheMostSpecificIdentity(): void
        {
            $provider = new WordPressFeatureContextProvider();

            $this->assertFalse($provider->accessedInRequest('checkout'));
            $this->assertTrue($provider->accessedInRequest('checkout'));

            $GLOBALS['toggly_wordpress_current_user'] = (object) [
                'ID' => 7,
                'user_email' => 'member@example.test',
            ];
            $this->assertSame('member@example.test', $provider->getContextIdentifier());
            $this->assertTrue($provider->accessedInRequestWithContext('checkout', ['userId' => 'context-id']));
            $this->assertSame('context-id', $provider->getContextIdentifierWithContext(['userId' => 'context-id']));
            $this->assertSame('legacy-id', $provider->getContextIdentifierWithContext(['user_id' => 'legacy-id']));
            $this->assertSame('context@example.test', $provider->getContextIdentifierWithContext(['email' => 'context@example.test']));
        }

        public function testContextProviderFallsBackFromUserIdToRemoteAddress(): void
        {
            $provider = new WordPressFeatureContextProvider();
            $GLOBALS['toggly_wordpress_current_user'] = (object) ['ID' => 9, 'user_email' => ''];
            $this->assertSame('9', $provider->getContextIdentifier());

            $GLOBALS['toggly_wordpress_current_user'] = (object) ['ID' => 0, 'user_email' => ''];
            $this->assertSame('203.0.113.10', $provider->getContextIdentifier());
            $this->assertSame('203.0.113.10', $provider->getContextIdentifierWithContext('not-an-array'));
        }

        public function testHttpClientMapsPsrRequestToTheWordPressHttpApi(): void
        {
            $request = (new WordPressRequest('POST', 'https://api.example.test/definitions'))
                ->withHeader('Accept', ['application/json', 'application/problem+json'])
                ->withBody(new WordPressStream('{"refresh":true}'));

            $response = (new WordPressHttpClient())->sendRequest($request);

            $this->assertSame('https://api.example.test/definitions', $GLOBALS['toggly_wordpress_http_request']['url']);
            $this->assertSame('POST', $GLOBALS['toggly_wordpress_http_request']['args']['method']);
            $this->assertSame('application/json, application/problem+json', $GLOBALS['toggly_wordpress_http_request']['args']['headers']['accept']);
            $this->assertSame('{"refresh":true}', $GLOBALS['toggly_wordpress_http_request']['args']['body']);
            $this->assertSame(201, $response->getStatusCode());
            $this->assertSame('request-1', $response->getHeaderLine('x-request-id'));
        }

        public function testHttpClientThrowsThePsrClientExceptionForWordPressErrors(): void
        {
            $GLOBALS['toggly_wordpress_http_response'] = new \WP_Error('upstream is unavailable');

            $this->expectException(WordPressHttpClientException::class);
            $this->expectExceptionMessage('upstream is unavailable');

            (new WordPressHttpClient())->sendRequest(new WordPressRequest('GET', 'https://api.example.test/definitions'));
        }

        public function testStreamCloseMakesTheInMemoryStreamUnavailable(): void
        {
            $stream = new WordPressStream('body');
            $stream->close();

            $this->assertFalse($stream->isReadable());
            $this->assertFalse($stream->isWritable());
            $this->assertFalse($stream->isSeekable());
            $this->expectException(\RuntimeException::class);
            $stream->read(1);
        }

        public function testRequestFactoryProducesIndependentRequestState(): void
        {
            $request = (new WordPressRequestFactory())->createRequest('GET', 'https://api.example.test/flags?scope=public');
            $requestTarget = $request->withRequestTarget('/definitions');

            $updated = $requestTarget
                ->withMethod('POST')
                ->withProtocolVersion('2')
                ->withUri(new WordPressUri('https://definitions.example.test/v2'))
                ->withHeader('X-Trace', 'first')
                ->withAddedHeader('x-trace', ['second', 'third'])
                ->withHeader('Accept', ['application/json', 'application/problem+json'])
                ->withBody(new WordPressStream('{"refresh":true}'));

            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/definitions', $requestTarget->getRequestTarget());
            $this->assertSame([], $request->getHeaders());
            $this->assertFalse($request->hasHeader('X-Trace'));
            $this->assertSame('POST', $updated->getMethod());
            $this->assertSame('2', $updated->getProtocolVersion());
            $this->assertSame('https://definitions.example.test/v2', (string) $updated->getUri());
            $this->assertSame(['first', 'second', 'third'], $updated->getHeader('X-Trace'));
            $this->assertSame('first, second, third', $updated->getHeaderLine('x-trace'));
            $this->assertSame('{"refresh":true}', (string) $updated->getBody());

            $withoutTrace = $updated->withoutHeader('X-Trace');
            $this->assertFalse($withoutTrace->hasHeader('x-trace'));
            $this->assertSame([], $withoutTrace->getHeader('x-trace'));
            $this->assertSame('application/json, application/problem+json', $withoutTrace->getHeaderLine('accept'));
        }

        public function testResponseRetainsWordPressMetadataAcrossUpdates(): void
        {
            $response = new WordPressResponse([
                'response' => ['code' => 201, 'message' => 'Created'],
                'headers' => [
                    'X-Request-Id' => 'request-1',
                    'X-Cache-Tags' => ['feature', 'definitions'],
                ],
                'body' => 'created',
            ]);

            $updated = $response
                ->withStatus(202, 'Accepted')
                ->withHeader('X-Request-Id', 'replacement')
                ->withAddedHeader('x-request-id', 'follow-up')
                ->withoutHeader('X-Cache-Tags')
                ->withBody(new WordPressStream('accepted'));

            $this->assertSame(201, $response->getStatusCode());
            $this->assertSame('Created', $response->getReasonPhrase());
            $this->assertSame('1.1', $response->getProtocolVersion());
            $this->assertTrue($response->hasHeader('x-cache-tags'));
            $this->assertSame(['x-request-id' => ['request-1'], 'x-cache-tags' => ['feature', 'definitions']], $response->getHeaders());
            $this->assertSame('feature, definitions', $response->getHeaderLine('X-Cache-Tags'));
            $this->assertSame('created', (string) $response->getBody());
            $this->assertSame(202, $updated->getStatusCode());
            $this->assertSame('Accepted', $updated->getReasonPhrase());
            $this->assertSame(['replacement', 'follow-up'], $updated->getHeader('X-Request-Id'));
            $this->assertFalse($updated->hasHeader('x-cache-tags'));
            $this->assertSame([], $updated->getHeader('x-cache-tags'));
            $this->assertSame('accepted', (string) $updated->getBody());
        }

        public function testStreamTracksCursorUpdatesAndDetachMakesItUnavailable(): void
        {
            $stream = new WordPressStream('abcdef');

            $this->assertSame(6, $stream->getSize());
            $stream->seek(2);
            $this->assertSame(2, $stream->tell());
            $this->assertSame('cd', $stream->read(2));
            $stream->seek(-1, SEEK_CUR);
            $this->assertSame('d', $stream->read(1));
            $stream->seek(-2, SEEK_END);
            $this->assertSame('ef', $stream->getContents());
            $this->assertTrue($stream->eof());

            $stream->rewind();
            $this->assertSame(2, $stream->write('XY'));
            $this->assertSame('cdef', $stream->getContents());
            $this->assertNull($stream->getMetadata('wrapper_type'));

            $detached = new WordPressStream('temporary');
            $this->assertNull($detached->detach());
            $this->assertNull($detached->getSize());
            $this->assertSame('', (string) $detached);
        }

        public function testUriMutatorsRetainTheOtherUriComponents(): void
        {
            $uri = new WordPressUri('https://user:pass@example.test:8443/flags?scope=admin#details');

            $this->assertSame('https', $uri->getScheme());
            $this->assertSame('example.test:8443', $uri->getAuthority());
            $this->assertSame('example.test', $uri->getHost());
            $this->assertSame(8443, $uri->getPort());
            $this->assertSame('/flags', $uri->getPath());
            $this->assertSame('scope=admin', $uri->getQuery());
            $this->assertSame('details', $uri->getFragment());
            $this->assertSame('https://api.example.test/flags?scope=admin#details', (string) $uri->withHost('api.example.test'));
            $this->assertSame('https://example.test/definitions?scope=admin#details', (string) $uri->withPath('/definitions'));
        }

        public function testUriParsesRelativeTargets(): void
        {
            $relative = new WordPressUri('/flags');
            $this->assertSame('', $relative->getAuthority());
            $this->assertSame('', $relative->getScheme());
            $this->assertSame('', $relative->getHost());
            $this->assertNull($relative->getPort());
            $this->assertSame('/flags', $relative->getPath());
            $this->assertSame('', $relative->getQuery());
            $this->assertSame('', $relative->getFragment());
        }
    }
}
