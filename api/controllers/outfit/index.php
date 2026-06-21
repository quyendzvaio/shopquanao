<?php
require_once __DIR__ . '/../../config.php';

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
$search    = trim($_GET['search'] ?? '');

global $pdo;

$sql = "
    SELECT o.*, p1.name as product_name, p1.price as product_price,
           p2.name as paired_name, p2.price as paired_price, p2.image as paired_image
    FROM outfit_suggestions o
    JOIN products p1 ON o.product_id = p1.id
    JOIN products p2 ON o.paired_product_id = p2.id
    WHERE 1=1
";
$params = [];

if ($productId) {
    $sql .= " AND (o.product_id = ? OR o.paired_product_id = ?)";
    $params[] = $productId;
    $params[] = $productId;
}

if ($search) {
    $sql .= " AND (p1.name LIKE ? OR p2.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY o.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$outfits = $stmt->fetchAll();

if (!$outfits) {
    errorResponse('No outfit found', 404);
}

foreach ($outfits as &$o) {
    $o['id'] = (int)$o['id'];
    $o['product_id'] = (int)$o['product_id'];
    $o['paired_product_id'] = (int)$o['paired_product_id'];
    $o['product_price'] = (float)$o['product_price'];
    $o['paired_price'] = (float)$o['paired_price'];
}

jsonResponse(['outfits' => $outfits]);
