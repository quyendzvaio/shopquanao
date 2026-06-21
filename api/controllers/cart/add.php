<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
$userId = (int)$user['id'];
$data = getJsonInput();

$productId = (int)($data['product_id'] ?? 0);
$quantity  = max(1, (int)($data['quantity'] ?? 1));
$size      = $data['size'] ?? 'S';

if (!$productId) errorResponse('product_id is required', 400);

global $pdo;

// Check product exists + stock
$stmt = $pdo->prepare("SELECT id, price, stock FROM products WHERE id = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) errorResponse('Product not found', 404);
if ((int)$product['stock'] <= 0) errorResponse('Product is out of stock', 400);

// Check if already in cart
$stmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
$stmt->execute([$userId, $productId]);
$existing = $stmt->fetch();

if ($existing) {
    $newQty = (int)$existing['quantity'] + $quantity;
    $stmt = $pdo->prepare("UPDATE cart SET quantity = ?, size = ? WHERE id = ?");
    $stmt->execute([$newQty, $size, $existing['id']]);
} else {
    $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $productId, $quantity, $size]);
}

jsonResponse(['message' => 'Added to cart'], 201);
