<?php

requireAdmin();

$data = getJsonInput();
$name  = trim($data['name'] ?? '');
$price = (float)($data['price'] ?? 0);
$stock = (int)($data['stock'] ?? 0);
$description = trim($data['description'] ?? '');
$categoryId  = isset($data['category_id']) ? (int)$data['category_id'] : null;
$subcategoryId = isset($data['subcategory_id']) ? (int)$data['subcategory_id'] : null;
$image       = trim($data['image'] ?? '');

if (!$name || $price <= 0) {
    errorResponse('name and price ( > 0 ) are required', 400);
}

global $pdo;
$productId = (new ProductAdminService($pdo))->create([
    'name' => $name,
    'price' => $price,
    'stock' => $stock,
    'description' => $description,
    'category_id' => $categoryId,
    'subcategory_id' => $subcategoryId,
    'image' => $image,
    'style_tags' => $data['style_tags'] ?? [],
]);

jsonResponse([
    'message' => 'Product created',
    'product_id' => $productId,
], 201);
