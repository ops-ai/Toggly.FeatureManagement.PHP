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
    }
}
