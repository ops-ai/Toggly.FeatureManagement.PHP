<?php

namespace Toggly\FeatureManagement\Telemetry;

/**
 * Native usage gRPC client wrapper (no ext-grpc class inheritance).
 *
 * The stub is duck-typed ({@code SendStats($msg, $metadata)->wait()}) so unit
 * tests can inject a fake without loading {@code \Grpc\BaseStub}.
 */
final class NativeUsageGrpcClient implements UsageGrpcClient
{
    /** @var object */
    private $stub;
    /** @var array<string, string[]> */
    private array $defaultMeta;

    /**
     * @param object $stub Object with SendStats($argument, array $metadata = [])
     * @param array<string, string[]> $defaultMeta
     */
    public function __construct(object $stub, array $defaultMeta)
    {
        $this->stub = $stub;
        $this->defaultMeta = $defaultMeta;
    }

    public function sendStats(array $payload, array $metadata = []): void
    {
        $meta = array_merge($this->defaultMeta, self::normalizeMetadata($metadata));
        $msg = GrpcPayloadConverter::featureStatFromPayload($payload);
        [$response, $status] = $this->stub->SendStats($msg, $meta)->wait();
        $ok = defined('\Grpc\STATUS_OK') ? \Grpc\STATUS_OK : 0;
        if (($status->code ?? $ok) !== $ok) {
            throw new \RuntimeException('Usage.SendStats failed: ' . ($status->details ?? 'unknown'));
        }
        unset($response);
    }

    public function close(): void
    {
    }

    /**
     * @param array<string, string> $metadata
     * @return array<string, string[]>
     */
    private static function normalizeMetadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            $out[strtolower((string) $key)] = [(string) $value];
        }

        return $out;
    }
}
