<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';
require_once __DIR__ . '/../../services/CartService.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo, $route_params;
$cartId = (int)($route_params['id'] ?? 0);
if (!$cartId) errorResponse('Cart item ID required', 400);

$data = getJsonInput();
$data['cart_id'] = $cartId;
try {
    jsonResponse((new CartService($pdo))->update($userId, $data));
} catch (InvalidArgumentException $error) {
    errorResponse($error->getMessage(), 400);
} catch (RuntimeException $error) {
    errorResponse($error->getMessage(), str_contains($error->getMessage(), 'not found') ? 404 : 409);
}
