<?php
require_once __DIR__ . '/../../config.php';

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);

if (!$id) errorResponse('Product ID required', 400);

$stmt = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) errorResponse('Product not found', 404);

$product['id']    = (int)$product['id'];
$product['price'] = (float)$product['price'];
$product['stock'] = (int)$product['stock'];
$product['category_id'] = $product['category_id'] ? (int)$product['category_id'] : null;

// Get sizes
$stmt = $pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
$stmt->execute([$id]);
$product['sizes'] = $stmt->fetchAll();

// Get average rating
$stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = ?");
$stmt->execute([$id]);
$rating = $stmt->fetch();
$product['avg_rating']      = $rating['avg_rating'] ? round((float)$rating['avg_rating'], 1) : null;
$product['total_reviews']   = (int)$rating['total_reviews'];

jsonResponse(['product' => $product]);
