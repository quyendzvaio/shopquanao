<?php
/**
 * RESTful API Router
 *
 * Entry point for all /api/* requests.
 * Routes are resolved by method + path pattern.
 */

// Always treat as JSON API
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

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
$routes = [
    // Auth
    'POST /auth/register'     => 'auth/register.php',
    'POST /auth/login'        => 'auth/login.php',
    'POST /auth/logout'       => 'auth/logout.php',
    'GET  /auth/me'           => 'auth/me.php',

    // Categories
    'GET  /categories'        => 'categories/list.php',

    // Products
    'GET  /products'          => 'products/list.php',
    'GET  /products/\d+'      => 'products/detail.php',
    'GET  /products/\d+/sizes'  => 'products/sizes.php',
    'GET  /products/\d+/reviews' => 'products/reviews.php',
    'POST /products/\d+/reviews' => 'products/add_review.php',

    // Cart
    'GET  /cart'              => 'cart/list.php',
    'POST /cart'              => 'cart/add.php',
    'PUT  /cart/\d+'          => 'cart/update.php',
    'DELETE /cart/\d+'        => 'cart/remove.php',

    // Orders
    'POST /orders'            => 'orders/create.php',
    'GET  /orders'            => 'orders/list.php',
    'GET  /orders/\d+'        => 'orders/detail.php',

    // Chatbot
    'POST /chatbot'           => 'chatbot/index.php',
    'GET  /chatbot/history'   => 'chatbot/history.php',

    // Chatbot support endpoints
    'GET  /size-guide'        => 'size-guide/index.php',
    'GET  /faq'               => 'faq/index.php',
    'GET  /outfit'            => 'outfit/index.php',
    'GET  /knowledge/search'  => 'knowledge/search.php',

    // Service-to-service MCP bridge (blocked at the public Nginx layer)
    'POST /internal/mcp'      => 'internal/mcp.php',

    // Admin
    'GET  /admin/dashboard'   => 'admin/dashboard.php',
    'GET  /admin/products'    => 'admin/products.php',
    'POST /admin/products'    => 'admin/product_create.php',
    'PUT  /admin/products/\d+' => 'admin/product_update.php',
    'DELETE /admin/products/\d+' => 'admin/product_delete.php',
    'GET  /admin/orders'      => 'admin/orders.php',
    'PUT  /admin/orders/\d+'  => 'admin/order_status.php',
    'GET  /admin/users'       => 'admin/users.php',
    'PUT  /admin/users/\d+'   => 'admin/user_action.php',
];

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
$handlerFile = __DIR__ . '/controllers/' . $matched;

if (!file_exists($handlerFile)) {
    errorResponse("Handler not implemented: $matched", 501);
}

// Pass params to handler via $GLOBALS for simplicity
$GLOBALS['route_params'] = $params;

require $handlerFile;
