<?php

namespace Toggly\FeatureManagement\Telemetry;

/**
 * Native metrics gRPC client wrapper (no ext-grpc class inheritance).
 *
 * The stub is duck-typed ({@code SendMetrics($msg, $metadata)->wait()}) so unit
 * tests can inject a fake without loading {@code \Grpc\BaseStub}.
 */
final class NativeMetricsGrpcClient implements MetricsGrpcClient
{
    /** @var object */
    private $stub;
    /** @var array<string, string[]> */
    private array $defaultMeta;

    /**
     * @param object $stub Object with SendMetrics($argument, array $metadata = [])
     * @param array<string, string[]> $defaultMeta
     */
    public function __construct(object $stub, array $defaultMeta)
    {
        $this->stub = $stub;
        $this->defaultMeta = $defaultMeta;
    }

    public function sendMetrics(array $payload, array $metadata = []): void
    {
        $meta = array_merge($this->defaultMeta, self::normalizeMetadata($metadata));
        $msg = GrpcPayloadConverter::metricStatFromPayload($payload);
        [$response, $status] = $this->stub->SendMetrics($msg, $meta)->wait();
        $ok = defined('\Grpc\STATUS_OK') ? \Grpc\STATUS_OK : 0;
        if (($status->code ?? $ok) !== $ok) {
            throw new \RuntimeException('Metrics.SendMetrics failed: ' . ($status->details ?? 'unknown'));
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
