<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

requireAdmin();

$data = getJsonInput();
$name  = trim($data['name'] ?? '');
$price = (float)($data['price'] ?? 0);
$stock = (int)($data['stock'] ?? 0);
$description = trim($data['description'] ?? '');
$categoryId  = isset($data['category_id']) ? (int)$data['category_id'] : null;
$image       = trim($data['image'] ?? '');

if (!$name || $price <= 0) {
    errorResponse('name and price ( > 0 ) are required', 400);
}

global $pdo;
$stmt = $pdo->prepare("INSERT INTO products (name, price, stock, description, category_id, image) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$name, $price, $stock, $description, $categoryId, $image]);

jsonResponse([
    'message' => 'Product created',
    'product_id' => (int)$pdo->lastInsertId(),
], 201);
