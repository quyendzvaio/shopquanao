<?php
require_once __DIR__ . '/../../config.php';

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);

if (!$id) errorResponse('Product ID required', 400);

$stmt = $pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
$stmt->execute([$id]);
$sizes = $stmt->fetchAll();

jsonResponse(['sizes' => $sizes]);
