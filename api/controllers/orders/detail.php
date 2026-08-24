<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';
require_once __DIR__ . '/../../services/OrderService.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo, $route_params;
$orderId = (int)($route_params['id'] ?? 0);
if (!$orderId) errorResponse('Order ID required', 400);

try {
    jsonResponse((new OrderService($pdo))->detail($userId, $orderId));
} catch (RuntimeException $error) {
    errorResponse($error->getMessage(), 404);
}
