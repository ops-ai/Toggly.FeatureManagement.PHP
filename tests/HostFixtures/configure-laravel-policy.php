<?php

declare(strict_types=1);

if ($argc !== 3) {
    throw new InvalidArgumentException('Usage: configure-laravel-policy.php <host-directory> <laravel-version>');
}

[$script, $hostDirectory, $laravelVersion] = $argv;

/** @var array<string, list<string>> $retainedHostAdvisories */
$retainedHostAdvisories = [
    '10.3.3' => [
        'PKSA-m5cs-t1y6-qpcs',
        'PKSA-3r5d-mb8f-1qw9',
        'PKSA-mdq4-51ck-6kdq',
        'PKSA-8qx3-n5y5-vvnd',
        'PKSA-w7xr-vk7n-rstm',
    ],
    '11.6.1' => [
        'PKSA-m5cs-t1y6-qpcs',
        'PKSA-3r5d-mb8f-1qw9',
        'PKSA-mdq4-51ck-6kdq',
        'PKSA-8qx3-n5y5-vvnd',
        'PKSA-w7xr-vk7n-rstm',
        'PKSA-q46n-4fdk-zjr4',
        'PKSA-qzrn-rnz3-85w1',
    ],
];

if (!isset($retainedHostAdvisories[$laravelVersion])) {
    exit(0);
}

$manifestPath = rtrim($hostDirectory, DIRECTORY_SEPARATOR) . '/composer.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest)) {
    throw new RuntimeException("Laravel fixture manifest is not an object: {$manifestPath}");
}

$ignored = $manifest['config']['policy']['advisories']['ignore-id'] ?? [];
if (!is_array($ignored)) {
    throw new RuntimeException("Laravel fixture policy acknowledgements are not an object: {$manifestPath}");
}

foreach ($retainedHostAdvisories[$laravelVersion] as $advisoryId) {
    $ignored[$advisoryId] = [
        'on-audit' => false,
        'reason' => "Retained Laravel {$laravelVersion} fixture; keep this EOL advisory visible to audit.",
    ];
}

$manifest['config']['policy']['advisories']['ignore-id'] = $ignored;
file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
);
