<?php

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);

if (!$id) errorResponse('Product ID required', 400);

$product = (new ProductService($pdo))->detailWithSizes($id);

if (!$product) errorResponse('Product not found', 404);

// Get average rating
$stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = ?");
$stmt->execute([$id]);
$rating = $stmt->fetch(PDO::FETCH_ASSOC);
$product['avg_rating']      = $rating['avg_rating'] ? round((float)$rating['avg_rating'], 1) : null;
$product['total_reviews']   = (int)$rating['total_reviews'];

jsonResponse(['product' => $product]);
