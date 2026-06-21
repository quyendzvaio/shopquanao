<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
$userId = (int)$user['id'];

$data = getJsonInput();

global $pdo;

try {
    $pdo->beginTransaction();

    // Get user's cart items
    $stmt = $pdo->prepare("SELECT product_id, quantity FROM cart WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cartItems = $stmt->fetchAll();

    if (!$cartItems) errorResponse('Cart is empty', 400);

    // Check stock for each item
    foreach ($cartItems as $item) {
        $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = ?");
        $stmt->execute([$item['product_id']]);
        $stock = (int)$stmt->fetchColumn();
        if ($stock <= 0) {
            errorResponse("Product ID {$item['product_id']} is out of stock", 400);
        }
    }

    $totalPrice = 0;
    foreach ($cartItems as $item) {
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmt->execute([$item['product_id']]);
        $price = (float)$stmt->fetchColumn();
        $totalPrice += $price * (int)$item['quantity'];
    }

    // Create order
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_price, status, created_at) VALUES (?, ?, 'Chờ xử lý', NOW())");
    $stmt->execute([$userId, $totalPrice]);
    $orderId = (int)$pdo->lastInsertId();

    // Create order items
    foreach ($cartItems as $item) {
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmt->execute([$item['product_id']]);
        $price = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $price]);
    }

    // Clear cart
    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->execute([$userId]);

    $pdo->commit();

    jsonResponse([
        'message' => 'Order placed successfully',
        'order_id' => $orderId,
    ], 201);

} catch (Exception $e) {
    $pdo->rollBack();
    errorResponse('Order failed: ' . $e->getMessage(), 500);
}
