<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();

global $pdo, $route_params;
$productId = (int)($route_params['id'] ?? 0);

if (!$productId) errorResponse('Product ID required', 400);

$data = getJsonInput();
$rating  = (int)($data['rating'] ?? 0);
$comment = trim($data['comment'] ?? '');

if ($rating < 1 || $rating > 5) errorResponse('Rating must be 1-5', 400);
if (!$comment) errorResponse('Comment is required', 400);

$stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
$stmt->execute([$productId]);
if (!$stmt->fetch()) errorResponse('Product not found', 404);

$stmt = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
$stmt->execute([$productId, $user['id'], $rating, $comment]);

jsonResponse(['message' => 'Review submitted successfully'], 201);
