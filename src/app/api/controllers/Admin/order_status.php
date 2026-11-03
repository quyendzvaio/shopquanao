<?php

requireAdmin();

global $pdo, $route_params;
$orderId = (int)($route_params['id'] ?? 0);
if (!$orderId) errorResponse('Order ID required', 400);

$data = getJsonInput();
$status = $data['status'] ?? '';

$allowed = OrderService::STATUSES;
if (!in_array($status, $allowed)) {
    errorResponse("Invalid status. Allowed: " . implode(', ', $allowed), 400);
}

$affected = (new OrderService($pdo))->updateStatus($orderId, $status);

if ($affected === 0) errorResponse('Order not found', 404);

jsonResponse(['message' => 'Order status updated']);
