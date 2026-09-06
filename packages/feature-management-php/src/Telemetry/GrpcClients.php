<?php

namespace Toggly\FeatureManagement\Telemetry;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Toggly\FeatureManagement\SdkIdentity;

/**
 * Optional native gRPC transport for Usage.SendStats and Metrics.SendMetrics.
 *
 * Requires the {@code grpc} PHP extension and the optional {@code google/protobuf}
 * Composer package. When either is missing, {@see create()} returns null (soft-fail).
 *
 * Metadata: PHP's gRPC extension lowercases HTTP/2 metadata keys. This client sends
 * {@see GRPC_USER_AGENT_METADATA_KEY} ({@code ua}); semantics match .NET/Go/Node {@code UA}.
 */
final class GrpcClients
{
    public const DEFAULT_METRICS_BASE_URL = 'https://app.toggly.io/';

    /**
     * gRPC metadata key for the SDK user-agent (lowercase for PHP ext-grpc).
     */
    public const GRPC_USER_AGENT_METADATA_KEY = 'ua';

    private UsageGrpcClient $usage;
    private MetricsGrpcClient $metrics;
    /** @var callable|null */
    private $onClose;

    public function __construct(UsageGrpcClient $usage, MetricsGrpcClient $metrics, ?callable $onClose = null)
    {
        $this->usage = $usage;
        $this->metrics = $metrics;
        $this->onClose = $onClose;
    }

    public function usage(): UsageGrpcClient
    {
        return $this->usage;
    }

    public function metrics(): MetricsGrpcClient
    {
        return $this->metrics;
    }

    public function close(): void
    {
        if ($this->onClose !== null) {
            ($this->onClose)();
            $this->onClose = null;
        }
    }

    /**
     * True when ext-grpc and google/protobuf are loadable.
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('grpc')) {
            return false;
        }
        if (!class_exists(\Google\Protobuf\Internal\Message::class)) {
            return false;
        }
        if (!class_exists(\Grpc\ChannelCredentials::class)) {
            return false;
        }

        return true;
    }

    /**
     * Dial usage + metrics stubs. Returns null when gRPC deps are missing (soft-fail).
     */
    public static function create(
        string $metricsBaseUrl,
        ?string $userAgent = null,
        ?LoggerInterface $logger = null
    ): ?self {
        $logger = $logger ?? new NullLogger();
        if (!self::isAvailable()) {
            return null;
        }

        try {
            require_once dirname(__DIR__, 2) . '/resources/grpc/native_grpc_stubs.php';

            $target = self::grpcTarget($metricsBaseUrl !== '' ? $metricsBaseUrl : self::DEFAULT_METRICS_BASE_URL);
            $ua = $userAgent !== null && $userAgent !== '' ? $userAgent : SdkIdentity::userAgent();
            $credentials = \Grpc\ChannelCredentials::createSsl();
            $opts = ['credentials' => $credentials];

            $usageStub = new UsageServiceClient($target, $opts);
            $metricsStub = new MetricsServiceClient($target, $opts);
            $defaultMeta = [self::GRPC_USER_AGENT_METADATA_KEY => [$ua]];

            $usage = new NativeUsageGrpcClient($usageStub, $defaultMeta);
            $metrics = new NativeMetricsGrpcClient($metricsStub, $defaultMeta);

            return new self($usage, $metrics, static function () use ($usageStub, $metricsStub): void {
                unset($usageStub, $metricsStub);
            });
        } catch (\Throwable $e) {
            $logger->warning('Failed to create Toggly gRPC clients; telemetry soft-fail', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Derive host:port for a gRPC channel from a metrics base URL.
     */
    public static function grpcTarget(string $baseUrl): string
    {
        $raw = trim($baseUrl);
        if ($raw === '') {
            return 'app.toggly.io:443';
        }
        if (strpos($raw, '://') === false) {
            $raw = 'https://' . $raw;
        }
        $parts = parse_url($raw);
        $host = $parts['host'] ?? ($parts['path'] ?? '');
        $host = rtrim((string) $host, '/');
        if ($host === '') {
            return 'app.toggly.io:443';
        }
        $port = $parts['port'] ?? null;
        if ($port !== null) {
            return $host . ':' . $port;
        }
        if (strpos($host, ':') !== false) {
            return $host;
        }

        return $host . ':443';
    }

    /**
     * Build a protobuf Timestamp array for wire payloads.
     *
     * @return array{seconds: int, nanos: int}
     */
    public static function toProtobufTimestamp(?int $unixSeconds = null): array
    {
        return [
            'seconds' => $unixSeconds ?? time(),
            'nanos' => 0,
        ];
    }
}
