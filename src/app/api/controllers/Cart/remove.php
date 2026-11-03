<?php

$user = authenticate();
$userId = (int)$user['id'];

global $pdo, $route_params;
$cartId = (int)($route_params['id'] ?? 0);
if (!$cartId) errorResponse('Cart item ID required', 400);

try {
    jsonResponse((new CartService($pdo))->remove($userId, $cartId));
} catch (PDOException $error) {
    databaseErrorResponse($error);
} catch (RuntimeException $error) {
    errorResponse($error->getMessage(), 404);
}
