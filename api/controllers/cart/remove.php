<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';
require_once __DIR__ . '/../../services/CartService.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo, $route_params;
$cartId = (int)($route_params['id'] ?? 0);
if (!$cartId) errorResponse('Cart item ID required', 400);

try {
    jsonResponse((new CartService($pdo))->remove($userId, $cartId));
} catch (RuntimeException $error) {
    errorResponse($error->getMessage(), 404);
}
