<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo;
$stmt = $pdo->prepare("
    SELECT c.id as cart_id, c.product_id, p.name, p.price, p.image, c.quantity, c.size, (p.price * c.quantity) as subtotal
    FROM cart c
    JOIN products p ON c.product_id = p.id
    WHERE c.user_id = ?
");
$stmt->execute([$userId]);
$items = $stmt->fetchAll();

$total = 0;
foreach ($items as &$item) {
    $item['cart_id']   = (int)$item['cart_id'];
    $item['product_id']= (int)$item['product_id'];
    $item['price']     = (float)$item['price'];
    $item['quantity']  = (int)$item['quantity'];
    $item['subtotal']  = (float)$item['subtotal'];
    $total += $item['subtotal'];
}

jsonResponse(['cart' => $items, 'total' => round($total, 2)]);
