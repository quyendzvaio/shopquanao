<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo, $route_params;
$cartId = (int)($route_params['id'] ?? 0);
if (!$cartId) errorResponse('Cart item ID required', 400);

$stmt = $pdo->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
$stmt->execute([$cartId, $userId]);

if ($stmt->rowCount() === 0) errorResponse('Cart item not found', 404);

jsonResponse(['message' => 'Removed from cart']);
