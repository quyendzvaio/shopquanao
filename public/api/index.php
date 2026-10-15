<?php
/**
 * RESTful API Router
 *
 * Entry point for all /api/* requests.
 * Routes are resolved by method + path pattern.
 */

// Always treat as JSON API
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/src/Http/helpers.php';
require_once dirname(__DIR__, 2) . '/src/Http/middleware.php';

// ---- Parse request ----
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip base path so we get a clean route like /auth/login
// If behind a subdirectory, adjust the prefix here.
$base = '/api';
$route = str_replace($base, '', $uri);
$route = '/' . trim($route, '/');
if ($route === '/') $route = '';

// ---- Routing table ----
// Format: ['METHOD /path', 'handler_file']
$routes = require dirname(__DIR__, 2) . '/src/Http/routes.php';

// ---- Match ----
$matched = null;
$params = [];

foreach ($routes as $pattern => $handler) {
    [$pMethod, $pPath] = preg_split('/\s+/', $pattern, 2);

    if (strtoupper($method) !== strtoupper($pMethod)) {
        continue;
    }

    // Convert route pattern to regex
    $regex = '#^' . $pPath . '$#';
    if (preg_match($regex, $route, $m)) {
        $matched = $handler;
        // Extract numeric params from match groups
        $params = array_values(array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
        // Also grab first numeric group as :id
        $params['id'] = $m[0] ?? null;
        // Find the first capture group for id
        if (preg_match_all('/\d+/', $route, $numMatches)) {
            $params['id'] = $numMatches[0][0] ?? null;
        }
        break;
    }
}

if (!$matched) {
    errorResponse("Route not found: $method $route", 404);
}

// ---- Dispatch ----
$handlerFile = dirname(__DIR__, 2) . '/src/Http/Controllers/' . $matched;

if (!file_exists($handlerFile)) {
    errorResponse("Handler not implemented: $matched", 501);
}

// Pass params to handler via $GLOBALS for simplicity
$GLOBALS['route_params'] = $params;

require $handlerFile;
