<?php

declare(strict_types=1);

if ($argc !== 3) {
    throw new InvalidArgumentException('Usage: configure-laravel-policy.php <host-directory> <laravel-version>');
}

[$script, $hostDirectory, $laravelVersion] = $argv;

// Laravel 10/11 host coverage was dropped (Packagist advisory PKSA-b41t-wbzk-9q51
// blocks every laravel/framework 10.x and 11.x release). Supported CI hosts are
// Laravel 12 and 13 only. Do not acknowledge or ignore security advisories here.
unset($script, $hostDirectory, $laravelVersion);
exit(0);
