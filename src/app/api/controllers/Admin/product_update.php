<?php

requireAdmin();

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);
if (!$id) errorResponse('Product ID required', 400);

$data = getJsonInput();

$fields = [];
$params = [];

foreach (['name', 'price', 'stock', 'description', 'category_id', 'subcategory_id', 'image'] as $f) {
    if (isset($data[$f])) {
        $fields[] = "$f = ?";
        $params[] = $data[$f];
    }
}

if (array_key_exists('style_tags', $data)) {
    $fields[] = "style_tags = ?";
    $params[] = StyleTag::toColumn((array)$data['style_tags']);
}

if (!$fields) errorResponse('No fields to update', 400);

$params[] = $id;
$sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

if ($stmt->rowCount() === 0) {
    // Check if product exists
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) errorResponse('Product not found', 404);
}

jsonResponse(['message' => 'Product updated']);
