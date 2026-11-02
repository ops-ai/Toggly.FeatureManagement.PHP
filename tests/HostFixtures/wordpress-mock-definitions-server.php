<?php

declare(strict_types=1);

/**
 * Minimal Toggly definitions API mock for WordPress host CI.
 *
 * Serves unsigned definition payloads at:
 *   GET /definitions/{appKey}/{environment}
 *
 * Start with PHP's built-in server:
 *   php -S 127.0.0.1:PORT tests/HostFixtures/wordpress-mock-definitions-server.php
 */

$definitionsPath = __DIR__ . '/wordpress-definitions.json';
$definitionsJson = file_get_contents($definitionsPath);
if ($definitionsJson === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"missing definitions fixture"}';
    return true;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET' && preg_match('#^/definitions/([^/]+)/([^/]+)/?$#', $path) === 1) {
    header('Content-Type: application/json');
    header('ETag: "wordpress-ci-defs-v1"');
    header('Cache-Control: no-store');
    echo $definitionsJson;
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{"error":"not found","path":' . json_encode($path) . '}';
return true;
