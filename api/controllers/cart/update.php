<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo, $route_params;
$cartId = (int)($route_params['id'] ?? 0);
if (!$cartId) errorResponse('Cart item ID required', 400);

$data = getJsonInput();

// Verify ownership
$stmt = $pdo->prepare("SELECT id FROM cart WHERE id = ? AND user_id = ?");
$stmt->execute([$cartId, $userId]);
if (!$stmt->fetch()) errorResponse('Cart item not found', 404);

if (isset($data['quantity'])) {
    $qty = max(1, (int)$data['quantity']);
    $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
    $stmt->execute([$qty, $cartId]);
}

if (isset($data['size'])) {
    $stmt = $pdo->prepare("UPDATE cart SET size = ? WHERE id = ?");
    $stmt->execute([$data['size'], $cartId]);
}

jsonResponse(['message' => 'Cart updated']);
