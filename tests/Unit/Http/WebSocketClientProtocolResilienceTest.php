<?php

namespace Toggly\FeatureManagement\Http {
    function stream_socket_client($address, &$errno = null, &$errstr = null, ...$options): mixed
    {
        if ($address === 'tcp://loopback.test:8080' && array_key_exists('toggly_protocol_test_socket', $GLOBALS)) {
            $errno = 0;
            $errstr = '';
            return $GLOBALS['toggly_protocol_test_socket'];
        }

        return \stream_socket_client($address, $errno, $errstr, ...$options);
    }

    function fgets($stream, ...$options): string|false
    {
        if ($stream === ($GLOBALS['toggly_protocol_test_socket'] ?? null)
            && array_key_exists('toggly_protocol_test_handshake_lines', $GLOBALS)) {
            return array_shift($GLOBALS['toggly_protocol_test_handshake_lines']) ?? false;
        }

        return \fgets($stream, ...$options);
    }
}

namespace Toggly\FeatureManagement\Tests\Unit\Http {

use PHPUnit\Framework\TestCase;
use Toggly\FeatureManagement\Http\WebSocketClient;

final class WebSocketClientProtocolResilienceTest extends TestCase
{
    private array $testSockets = [];

    protected function setUp(): void
    {
        unset($GLOBALS['toggly_protocol_test_socket'], $GLOBALS['toggly_protocol_test_handshake_lines']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['toggly_protocol_test_socket'], $GLOBALS['toggly_protocol_test_handshake_lines']);
        foreach ($this->testSockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->testSockets = [];
        parent::tearDown();
    }

    public function testNativeConnectionsForwardContextAndErrorReferencesOutsideTheFixture(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);
        $address = 'tcp://' . stream_socket_get_name($server, false);
        $client = $peer = null;
        try {
            $GLOBALS['toggly_protocol_test_socket'] = $this->socket('');
            $context = stream_context_create(['socket' => ['bindto' => '127.0.0.1:0']]);
            $client = \Toggly\FeatureManagement\Http\stream_socket_client(
                $address, $errno, $errstr, 0.25, STREAM_CLIENT_CONNECT, $context
            );
            self::assertIsResource($client);
            self::assertSame(stream_context_get_options($context), stream_context_get_options($client));
            $peer = stream_socket_accept($server, 0.25);
            self::assertIsResource($peer);
            fwrite($peer, "native connection\n");
            self::assertSame("native connection\n", \Toggly\FeatureManagement\Http\fgets($client));

            fclose($server);
            $errno = 0;
            $errstr = '';
            self::assertFalse(@\Toggly\FeatureManagement\Http\stream_socket_client(
                $address, $errno, $errstr, 0.25, STREAM_CLIENT_CONNECT, $context
            ));
            self::assertNotSame(0, $errno);
            self::assertNotSame('', $errstr);
        } finally {
            foreach ([$client, $peer, $server] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function testNativeReadsForwardLengthEvenWhileAnotherSocketHasAFixture(): void
    {
        $fixture = $this->socket('');
        $native = $this->socket("native line\n");
        $GLOBALS['toggly_protocol_test_socket'] = $fixture;
        $GLOBALS['toggly_protocol_test_handshake_lines'] = ["fixture line\n"];
        try {
            self::assertSame('nat', \Toggly\FeatureManagement\Http\fgets($native, 4));
            self::assertSame("ive line\n", \Toggly\FeatureManagement\Http\fgets($native));
            self::assertSame(["fixture line\n"], $GLOBALS['toggly_protocol_test_handshake_lines']);
        } finally {
            fclose($fixture);
            fclose($native);
        }
    }

    public function testTickUnmasksAnUpdateFrameAndDispatchesIt(): void
    {
        $updates = [];
        $client = new WebSocketClient();
        $this->set($client, 'socket', $this->socket($this->maskedFrame('{"type":"signing-key-updated"}')));
        $this->set($client, 'isRunning', true);
        $this->set($client, 'onUpdate', static function (bool $forceJwksRefresh) use (&$updates): void {
            $updates[] = $forceJwksRefresh;
        });

        $client->tick();

        self::assertSame([true], $updates);
        self::assertTrue($client->isRunning());
    }

    public function testFramesHandleExtendedPayloadsAndControlOpcodes(): void
    {
        $client = new WebSocketClient();
        $extended = str_repeat('a', 126);
        $this->set($client, 'socket', $this->socket(chr(0x81) . chr(126) . pack('n', 126) . $extended));
        self::assertSame($extended, $this->call($client, 'readFrame'));

        $veryExtended = str_repeat('b', 126);
        $this->set($client, 'socket', $this->socket(chr(0x81) . chr(127) . pack('J', 126) . $veryExtended));
        self::assertSame($veryExtended, $this->call($client, 'readFrame'));

        $pingFrame = chr(0x89) . chr(1) . 'p';
        $pingSocket = $this->socket($pingFrame);
        $this->set($client, 'socket', $pingSocket);
        self::assertSame('', $this->call($client, 'readFrame'));
        fseek($pingSocket, strlen($pingFrame));
        $pong = stream_get_contents($pingSocket);
        self::assertSame(0x8A, ord($pong[0]));
        self::assertSame('p', $this->unmask($pong));

        $this->set($client, 'socket', $this->socket(chr(0x88) . chr(0)));
        self::assertNull($this->call($client, 'readFrame'));
    }

    public function testClientFramesRemainMaskedAcrossExtendedPayloadLengths(): void
    {
        $client = new WebSocketClient();
        $socket = $this->socket('');
        $this->set($client, 'socket', $socket);

        $mediumPayload = str_repeat('m', 126);
        $this->call($client, 'sendFrame', $mediumPayload);
        rewind($socket);
        $mediumFrame = stream_get_contents($socket);
        self::assertSame(0x81, ord($mediumFrame[0]));
        self::assertSame(0xFE, ord($mediumFrame[1]));
        self::assertSame($mediumPayload, $this->unmask($mediumFrame));

        ftruncate($socket, 0);
        rewind($socket);
        $largePayload = str_repeat('l', 65536);
        $this->call($client, 'sendFrame', $largePayload);
        rewind($socket);
        $largeFrame = stream_get_contents($socket);
        self::assertSame(0xFF, ord($largeFrame[1]));
        self::assertSame($largePayload, $this->unmask($largeFrame));
    }

    public function testPublicLifecycleHandlesInactiveRuntimeAndClosedFrames(): void
    {
        $client = new WebSocketClient();
        $this->set($client, 'longRunningProcess', false);
        self::assertFalse($client->connect('ws://loopback.test:8080/frames', static function (): void {}));

        $this->set($client, 'longRunningProcess', true);
        $this->set($client, 'socket', $this->socket(''));
        $this->set($client, 'isRunning', true);
        $client->tick();

        self::assertFalse($client->isRunning());
        self::assertNull($this->get($client, 'socket'));
    }

    public function testUpdateMessagesDispatchOnlyRecognizedSignalsAndContainCallbackFailures(): void
    {
        $client = new WebSocketClient();
        $updates = [];
        $this->set($client, 'onUpdate', static function (bool $forceJwksRefresh) use (&$updates): void {
            $updates[] = $forceJwksRefresh;
        });

        foreach ([
            '{"type":"ping"}',
            '{"type":"flags-updated"}',
            '{"type":"update"}',
            '{"type":"sync"}',
            'flags-updated',
            'update',
            '{"type":"unknown"}',
            'unknown',
        ] as $message) {
            $this->call($client, 'handleMessage', $message);
        }
        self::assertSame([false, false, false, false, false], $updates);

        $this->set($client, 'onUpdate', static function (): void {
            throw new \RuntimeException('subscriber failed');
        });
        $this->call($client, 'handleMessage', '{"type":"update"}');
        $this->addToAssertionCount(1);
    }

    public function testProtocolUpgradeRetainsTheConnectionUntilAClientCloseFrame(): void
    {
        $socket = $this->socket('');
        $GLOBALS['toggly_protocol_test_socket'] = $socket;
        $GLOBALS['toggly_protocol_test_handshake_lines'] = [
            "HTTP/1.1 101 Switching Protocols\r\n",
            "Upgrade: websocket\r\n",
            "\r\n",
        ];
        $client = new WebSocketClient();

        self::assertTrue($client->isAvailable());
        self::assertTrue($client->connect('ws://loopback.test:8080/frames', static function (): void {}));
        self::assertTrue($client->isRunning());
        rewind($socket);
        $request = stream_get_contents($socket);
        self::assertStringContainsString("Upgrade: websocket\r\n", $request);
        self::assertStringContainsString("Sec-WebSocket-Version: 13\r\n", $request);

        $client->disconnect();
        self::assertFalse($client->isRunning());
    }

    public function testTickReconnectsAfterTheDelayWithRetainedConnectionDetails(): void
    {
        $initialSocket = $this->socket('');
        $GLOBALS['toggly_protocol_test_socket'] = $initialSocket;
        $GLOBALS['toggly_protocol_test_handshake_lines'] = ["HTTP/1.1 101 Switching Protocols\r\n", "\r\n"];
        $client = new WebSocketClient();
        self::assertTrue($client->connect('ws://loopback.test:8080/frames', static function (): void {}));

        $this->call($client, 'handleDisconnect');
        self::assertFalse($client->isRunning());
        $this->set($client, 'lastReconnectAttempt', time() - 6);

        $GLOBALS['toggly_protocol_test_socket'] = $this->socket('');
        $GLOBALS['toggly_protocol_test_handshake_lines'] = ["HTTP/1.1 101 Switching Protocols\r\n", "\r\n"];
        $client->tick();

        self::assertTrue($client->isRunning());
        $client->disconnect();
    }

    public function testDisconnectAndFailedHandshakeLeaveClientAvailableForFutureReconnect(): void
    {
        $client = new WebSocketClient();
        $this->set($client, 'socket', $this->socket(''));
        $this->set($client, 'isRunning', true);
        $this->call($client, 'handleDisconnect');

        self::assertFalse($client->isRunning());
        self::assertNull($this->get($client, 'socket'));
        self::assertIsInt($this->get($client, 'lastReconnectAttempt'));

        $GLOBALS['toggly_protocol_test_socket'] = $this->socket('');
        $GLOBALS['toggly_protocol_test_handshake_lines'] = ["HTTP/1.1 403 Forbidden\r\n", "\r\n"];
        self::assertFalse($client->connect('ws://loopback.test:8080/frames', static function (): void {}));
        self::assertFalse($client->isRunning());
    }

    private function socket(string $input)
    {
        $socket = fopen('php://temp', 'r+');
        $this->testSockets[] = $socket;
        fwrite($socket, $input);
        rewind($socket);
        return $socket;
    }

    private function maskedFrame(string $payload): string
    {
        $mask = "\x01\x02\x03\x04";
        $masked = '';
        foreach (str_split($payload) as $index => $character) {
            $masked .= chr(ord($character) ^ ord($mask[$index % 4]));
        }

        return chr(0x81) . chr(0x80 | strlen($payload)) . $mask . $masked;
    }

    private function unmask(string $frame): string
    {
        $length = ord($frame[1]) & 0x7F;
        $offset = 2;
        if ($length === 126) {
            $length = unpack('n', substr($frame, $offset, 2))[1];
            $offset += 2;
        } elseif ($length === 127) {
            $length = unpack('J', substr($frame, $offset, 8))[1];
            $offset += 8;
        }
        $mask = substr($frame, $offset, 4);
        $payload = substr($frame, $offset + 4, $length);
        for ($index = 0; $index < $length; $index++) {
            $payload[$index] = chr(ord($payload[$index]) ^ ord($mask[$index % 4]));
        }

        return $payload;
    }

    private function call(WebSocketClient $client, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($client, $method))->invoke($client, ...$arguments);
    }

    private function set(WebSocketClient $client, string $property, mixed $value): void
    {
        (new \ReflectionProperty($client, $property))->setValue($client, $value);
    }

    private function get(WebSocketClient $client, string $property): mixed
    {
        return (new \ReflectionProperty($client, $property))->getValue($client);
    }
}
}
