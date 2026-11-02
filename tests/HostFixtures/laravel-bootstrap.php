<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Toggly\FeatureManagement\Config\TogglySettings;
use Toggly\FeatureManagement\Contracts\FeatureSnapshotProviderInterface;
use Toggly\FeatureManagement\Core\FeatureManager;
use Toggly\FeatureManagement\Core\FeatureProvider;
use Toggly\FeatureManagement\Http\TogglyHttpClient;
use Toggly\Laravel\Facades\Toggly;
use Toggly\Laravel\Middleware\FeatureGateMiddleware;

if ($argc !== 2) {
    throw new InvalidArgumentException('Pass the Laravel application path.');
}

$applicationPath = $argv[1];
$autoload = $applicationPath . '/vendor/autoload.php';
$bootstrap = $applicationPath . '/bootstrap/app.php';

if (!is_file($autoload) || !is_file($bootstrap)) {
    throw new RuntimeException('Laravel host is missing its Composer autoloader or bootstrap file.');
}

require $autoload;

$app = require $bootstrap;
$app->make(Kernel::class)->bootstrap();

$definitionsPayload = json_encode([
    [
        'featureKey' => 'enabled-flag',
        'filters' => [
            ['name' => 'AlwaysOn'],
        ],
    ],
    [
        'featureKey' => 'disabled-flag',
        'filters' => [
            ['name' => 'AlwaysOff'],
        ],
    ],
], JSON_THROW_ON_ERROR);

// Mock the Toggly definitions API with a realistic payload. Queue enough
// identical 200s for any incidental follow-up GETs during the fixture.
$mockHandler = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json', 'ETag' => '"host-fixture-rev-1"'], $definitionsPayload),
    new Response(200, ['Content-Type' => 'application/json', 'ETag' => '"host-fixture-rev-1"'], $definitionsPayload),
    new Response(200, ['Content-Type' => 'application/json', 'ETag' => '"host-fixture-rev-1"'], $definitionsPayload),
]);

$mockedClient = new Client(['handler' => HandlerStack::create($mockHandler)]);
$requestFactory = new HttpFactory();

foreach ([
    ClientInterface::class,
    RequestFactoryInterface::class,
    TogglyHttpClient::class,
    FeatureProvider::class,
    FeatureManager::class,
    FeatureSnapshotProviderInterface::class,
    TogglySettings::class,
    'toggly',
] as $abstract) {
    $app->forgetInstance($abstract);
}

$app->instance(ClientInterface::class, $mockedClient);
$app->instance(RequestFactoryInterface::class, $requestFactory);

// Laravel 11+ skeletons default CACHE_STORE=database. Host fixtures do not
// migrate sqlite, so force an in-memory cache before Toggly resolves its
// snapshot provider.
config([
    'cache.default' => 'array',
    'toggly.app_key' => 'host-fixture-app-key',
    'toggly.environment' => 'Production',
    'toggly.base_url' => 'https://definitions.test/',
    'toggly.use_signed_definitions' => false,
    'toggly.enable_variants' => false,
    'toggly.enable_live_updates' => false,
    'toggly.snapshot_provider' => 'cache',
]);

$manager = $app->make(FeatureManager::class);
if (!$manager instanceof FeatureManager) {
    throw new RuntimeException('Laravel did not resolve the Toggly FeatureManager.');
}

$provider = $app->make(FeatureProvider::class);
$provider->refreshFeatures(true);

if (!Toggly::isEnabled('enabled-flag')) {
    throw new RuntimeException('Facade evaluation failed: enabled-flag should be on.');
}
if (Toggly::isEnabled('disabled-flag')) {
    throw new RuntimeException('Facade evaluation failed: disabled-flag should be off.');
}
if (Toggly::isEnabled('missing-flag')) {
    throw new RuntimeException('Facade evaluation failed: missing-flag should be off.');
}

$middleware = $app->make(FeatureGateMiddleware::class);
$passResponse = $middleware->handle(
    Request::create('/toggly-host-fixture/enabled', 'GET'),
    static fn () => response('feature-allowed', 200),
    'enabled-flag'
);
if ((string) $passResponse->getContent() !== 'feature-allowed' || $passResponse->getStatusCode() !== 200) {
    throw new RuntimeException('FeatureGateMiddleware failed to allow an enabled feature.');
}

$blocked = false;
try {
    $middleware->handle(
        Request::create('/toggly-host-fixture/disabled', 'GET'),
        static fn () => response('should-not-run', 200),
        'disabled-flag'
    );
} catch (Throwable $exception) {
    $blocked = method_exists($exception, 'getStatusCode')
        ? (int) $exception->getStatusCode() === 404
        : str_contains($exception->getMessage(), 'Feature not enabled');
}
if (!$blocked) {
    throw new RuntimeException('FeatureGateMiddleware failed to block a disabled feature.');
}

$bladeViewPath = $applicationPath . '/resources/views/toggly-host-fixture.blade.php';
file_put_contents($bladeViewPath, <<<'BLADE'
@feature('enabled-flag')
ENABLED_OK
@endfeature
@feature('disabled-flag')
DISABLED_LEAK
@endfeature
@unlessfeature('disabled-flag')
UNLESS_OK
@endunlessfeature
BLADE);

$bladeOutput = view('toggly-host-fixture')->render();
if (!str_contains($bladeOutput, 'ENABLED_OK')) {
    throw new RuntimeException('Blade @feature failed to render content for an enabled flag.');
}
if (str_contains($bladeOutput, 'DISABLED_LEAK')) {
    throw new RuntimeException('Blade @feature leaked content for a disabled flag.');
}
if (!str_contains($bladeOutput, 'UNLESS_OK')) {
    throw new RuntimeException('Blade @unlessfeature failed for a disabled flag.');
}

fwrite(STDOUT, "Toggly Laravel package discovery, FeatureManager init, and flag evaluation (facade/middleware/Blade) passed.\n");
