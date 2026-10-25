<?php

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);

if (!$id) errorResponse('Product ID required', 400);

$product = (new ProductService($pdo))->detailWithSizes($id);

if (!$product) errorResponse('Product not found', 404);

// Get average rating
$rating = (new ReviewService($pdo))->ratingSummary($id);
$product['avg_rating']      = $rating['avg_rating'];
$product['total_reviews']   = $rating['total_reviews'];

jsonResponse(['product' => $product]);
