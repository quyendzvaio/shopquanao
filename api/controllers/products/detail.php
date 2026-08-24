<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../services/Catalog/CatalogVariantHydrator.php';

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);

if (!$id) errorResponse('Product ID required', 400);

$stmt = $pdo->prepare("SELECT p.*, c.name as category_name, c.canonical_key as category,
        sc.canonical_key as subcategory, sc.display_name as subcategory_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id
    WHERE p.id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) errorResponse('Product not found', 404);

$product['id']    = (int)$product['id'];
$product['price'] = (float)$product['price'];
$product['stock'] = (int)$product['stock'];
$product['category_id'] = $product['category_id'] ? (int)$product['category_id'] : null;

// Get sizes
$stmt = $pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
$stmt->execute([$id]);
$product['sizes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
$enriched = (new CatalogVariantHydrator($pdo))->enrich([$product]);
$product = $enriched[0] ?? $product;

// Get average rating
$stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = ?");
$stmt->execute([$id]);
$rating = $stmt->fetch(PDO::FETCH_ASSOC);
$product['avg_rating']      = $rating['avg_rating'] ? round((float)$rating['avg_rating'], 1) : null;
$product['total_reviews']   = (int)$rating['total_reviews'];

jsonResponse(['product' => $product]);
