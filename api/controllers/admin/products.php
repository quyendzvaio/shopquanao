<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

requireAdmin();

global $pdo;
$stmt = $pdo->query("SELECT * FROM products ORDER BY id DESC");
$products = $stmt->fetchAll();

foreach ($products as &$p) {
    $p['id']    = (int)$p['id'];
    $p['price'] = (float)$p['price'];
    $p['stock'] = (int)$p['stock'];
    $p['category_id'] = $p['category_id'] ? (int)$p['category_id'] : null;
}

jsonResponse(['products' => $products]);
