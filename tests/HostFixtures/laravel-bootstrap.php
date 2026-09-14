<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Console\Kernel;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Toggly\FeatureManagement\Core\FeatureManager;

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

// The SDK consumes PSR-18 interfaces. A Laravel application owns these
// bindings; the fixture supplies Guzzle's implementations explicitly.
$app->singleton(ClientInterface::class, static fn (): Client => new Client());
$app->singleton(RequestFactoryInterface::class, static fn (): HttpFactory => new HttpFactory());

$manager = $app->make(FeatureManager::class);
if (!$manager instanceof FeatureManager) {
    throw new RuntimeException('Laravel did not resolve the Toggly FeatureManager.');
}

fwrite(STDOUT, "Toggly Laravel package discovery and FeatureManager initialization passed.\n");
