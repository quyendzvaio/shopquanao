<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

requireAdmin();

global $pdo, $route_params;
$orderId = (int)($route_params['id'] ?? 0);
if (!$orderId) errorResponse('Order ID required', 400);

$data = getJsonInput();
$status = $data['status'] ?? '';

$allowed = ['Chờ xử lý', 'Đang giao', 'Đã hoàn thành', 'Đã hủy'];
if (!in_array($status, $allowed)) {
    errorResponse("Invalid status. Allowed: " . implode(', ', $allowed), 400);
}

$stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
$stmt->execute([$status, $orderId]);

if ($stmt->rowCount() === 0) errorResponse('Order not found', 404);

jsonResponse(['message' => 'Order status updated']);
