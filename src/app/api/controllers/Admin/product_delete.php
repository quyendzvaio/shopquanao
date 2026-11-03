<?php

requireAdmin();

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);
if (!$id) errorResponse('Product ID required', 400);

$products = new ProductAdminService($pdo);

// Delete image if exists
$img = $products->imageOf($id);

if ($img && file_exists(dirname(__DIR__, 5) . '/public/assets/images/' . $img)) {
    unlink(dirname(__DIR__, 5) . '/public/assets/images/' . $img);
}

if ($products->delete($id) === 0) errorResponse('Product not found', 404);

jsonResponse(['message' => 'Product deleted']);
