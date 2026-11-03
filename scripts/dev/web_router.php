<?php

/**
 * Router for `php -S` used by scripts/dev/web_snapshot.php. It mimics the
 * production edge: /api/* goes to the REST front controller, an existing file
 * under public/ is served as-is, anything else falls through to public/index.php
 * (the nginx catch-all).
 */
$publicDir = dirname(__DIR__, 2) . '/public';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require $publicDir . '/api/index.php';
    return true;
}

$file = realpath($publicDir . $path);
if ($file !== false && is_file($file) && str_starts_with($file, $publicDir . '/')) {
    return false;
}

require $publicDir . '/index.php';
return true;
