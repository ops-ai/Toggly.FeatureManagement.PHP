<?php

namespace Toggly\FeatureManagement\Telemetry {
    function extension_loaded(string $name): bool
    {
        return $name === 'grpc' && array_key_exists('toggly_grpc_extension_available', $GLOBALS)
            ? $GLOBALS['toggly_grpc_extension_available']
            : \extension_loaded($name);
    }

    function class_exists(string $class, bool $autoload = true): bool
    {
        return $GLOBALS['toggly_grpc_class_exists'][$class] ?? \class_exists($class, $autoload);
    }
}

namespace Toggly\FeatureManagement\Tests\Unit\Telemetry {

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Toggly\FeatureManagement\Telemetry\GrpcClients;
use Toggly\FeatureManagement\Telemetry\MetricsGrpcClient;
use Toggly\FeatureManagement\Telemetry\UsageGrpcClient;

final class GrpcClientsCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['toggly_grpc_extension_available'], $GLOBALS['toggly_grpc_class_exists']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['toggly_grpc_extension_available'], $GLOBALS['toggly_grpc_class_exists']);
    }
    public function testExposesClientsAndClosesOwnedTransportOnlyOnce(): void
    {
        $usage = $this->createStub(UsageGrpcClient::class);
        $metrics = $this->createStub(MetricsGrpcClient::class);
        $closed = 0;
        $clients = new GrpcClients($usage, $metrics, static function () use (&$closed): void { $closed++; });

        $this->assertSame($usage, $clients->usage());
        $this->assertSame($metrics, $clients->metrics());
        $clients->close();
        $clients->close();
        $this->assertSame(1, $closed);
    }

    public function testTargetResolutionHandlesEmptySchemesPortsAndIpv6(): void
    {
        $this->assertSame('metrics.toggly.io:443', GrpcClients::grpcTarget(''));
        $this->assertSame('metrics.example.test:443', GrpcClients::grpcTarget('metrics.example.test/path'));
        $this->assertSame('metrics.example.test:8443', GrpcClients::grpcTarget('https://metrics.example.test:8443/ingest'));
        $this->assertSame('[2001:db8::1]', GrpcClients::grpcTarget('https://[2001:db8::1]/'));
        $this->assertSame('metrics.toggly.io:443', GrpcClients::grpcTarget('https:///'));
    }

    public function testMetricOriginRecognitionAndFallbackPreserveCustomOrigins(): void
    {
        $this->assertTrue(GrpcClients::isDefinitionsCdn('https://DEFINITIONS.toggly.io/path'));
        $this->assertTrue(GrpcClients::isProductApiHost('https://APP.toggly.io/v1'));
        $this->assertFalse(GrpcClients::isDefinitionsCdn('not a url'));
        $this->assertFalse(GrpcClients::isProductApiHost(null));
        $this->assertSame(GrpcClients::DEFAULT_METRICS_BASE_URL, GrpcClients::resolveHttpMetricsBaseUrl('https://app.toggly.io'));
        $this->assertSame('https://ingest.example.test/', GrpcClients::resolveHttpMetricsBaseUrl('https://ingest.example.test///'));
    }

    public function testTimestampUsesExplicitSecondsAndZeroNanos(): void
    {
        $this->assertSame(['seconds' => 1_700_000_000, 'nanos' => 0], GrpcClients::toProtobufTimestamp(1_700_000_000));
    }

    public function testCreateSoftFailsWhenGrpcExtensionIsUnavailable(): void
    {
        $this->assertFalse(GrpcClients::isAvailable());
        $this->assertNull(GrpcClients::create('https://metrics.example.test', 'coverage-agent/1'));
    }

    public function testAvailabilityChecksMissingProtobufAndChannelClasses(): void
    {
        $GLOBALS['toggly_grpc_extension_available'] = true;
        $GLOBALS['toggly_grpc_class_exists'] = [\Google\Protobuf\Internal\Message::class => false];
        $this->assertFalse(GrpcClients::isAvailable());

        $GLOBALS['toggly_grpc_class_exists'] = [
            \Google\Protobuf\Internal\Message::class => true,
            \Grpc\ChannelCredentials::class => false,
        ];
        $this->assertFalse(GrpcClients::isAvailable());
    }

    public function testCreateLogsAndSoftFailsWhenStubLoadingCannotComplete(): void
    {
        $GLOBALS['toggly_grpc_extension_available'] = true;
        $GLOBALS['toggly_grpc_class_exists'] = [
            \Google\Protobuf\Internal\Message::class => true,
            \Grpc\ChannelCredentials::class => true,
        ];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('soft-fail'));

        $this->assertNull(GrpcClients::create('https://metrics.example.test', null, $logger));
    }
}
}
