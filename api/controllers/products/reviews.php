<?php
require_once __DIR__ . '/../../config.php';

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);

if (!$id) errorResponse('Product ID required', 400);

$stmt = $pdo->prepare("SELECT r.*, u.username FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = ? ORDER BY r.created_at DESC");
$stmt->execute([$id]);
$reviews = $stmt->fetchAll();

foreach ($reviews as &$r) {
    $r['id']    = (int)$r['id'];
    $r['rating'] = (int)$r['rating'];
}

jsonResponse(['reviews' => $reviews]);
