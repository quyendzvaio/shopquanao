<?php

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
$search    = trim($_GET['search'] ?? '');

global $pdo;

$outfits = (new OutfitService($pdo))->suggestions($productId, $search);

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
