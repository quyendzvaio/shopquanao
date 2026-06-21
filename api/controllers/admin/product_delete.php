<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

requireAdmin();

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);
if (!$id) errorResponse('Product ID required', 400);

// Delete image if exists
$stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
$stmt->execute([$id]);
$img = $stmt->fetchColumn();

if ($img && file_exists(__DIR__ . '/../../images/' . $img)) {
    unlink(__DIR__ . '/../../images/' . $img);
}

$stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
$stmt->execute([$id]);

if ($stmt->rowCount() === 0) errorResponse('Product not found', 404);

jsonResponse(['message' => 'Product deleted']);
