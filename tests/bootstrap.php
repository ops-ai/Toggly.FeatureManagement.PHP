<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/../build/phpunit-attributes-shim.php';
// WordPress package sources and these stubs use PHP 8.0+ syntax (unions,
// constructor property promotion, mixed). Floor CI (7.4/8.0) excludes the
// WordPress suites, so skip loading stubs there to keep bootstrap parseable.
if (PHP_VERSION_ID >= 80100) {
    require __DIR__ . '/fixtures/WordPressRuntimeStubs.php';
}
