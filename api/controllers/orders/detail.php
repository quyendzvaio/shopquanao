<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo, $route_params;
$orderId = (int)($route_params['id'] ?? 0);
if (!$orderId) errorResponse('Order ID required', 400);

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();

if (!$order) errorResponse('Order not found', 404);

$order['id']         = (int)$order['id'];
$order['total_price'] = (float)$order['total_price'];

// Items
$stmt = $pdo->prepare("
    SELECT oi.*, p.name, p.image
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

foreach ($items as &$item) {
    $item['id']       = (int)$item['id'];
    $item['quantity'] = (int)$item['quantity'];
    $item['price']    = (float)$item['price'];
}

$order['items'] = $items;

jsonResponse(['order' => $order]);
