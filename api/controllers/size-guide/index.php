<?php
require_once __DIR__ . '/../../config.php';

$height = isset($_GET['height']) ? (int)$_GET['height'] : 0;
$weight = isset($_GET['weight']) ? (int)$_GET['weight'] : 0;
$catId  = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

if (!$height || !$weight) {
    errorResponse('Height and weight are required', 400);
}

global $pdo;

if ($catId) {
    $stmt = $pdo->prepare("SELECT * FROM size_guides WHERE category_id = ? ORDER BY FIELD(size_name, 'S','M','L','XL')");
    $stmt->execute([$catId]);
} else {
    $stmt = $pdo->query("SELECT * FROM size_guides ORDER BY category_id, FIELD(size_name, 'S','M','L','XL')");
}
$sizes = $stmt->fetchAll();

$recommended = null;
foreach ($sizes as $s) {
    $hOk = (!$s['height_from'] || $height >= (int)$s['height_from'])
        && (!$s['height_to'] || $height <= (int)$s['height_to']);
    $wOk = (!$s['weight_from'] || $weight >= (int)$s['weight_from'])
        && (!$s['weight_to'] || $weight <= (int)$s['weight_to']);
    if ($hOk && $wOk && !$recommended) {
        $recommended = $s;
    }
}

foreach ($sizes as &$s) {
    $s['id'] = (int)$s['id'];
    $s['height_from'] = $s['height_from'] ? (int)$s['height_from'] : null;
    $s['height_to'] = $s['height_to'] ? (int)$s['height_to'] : null;
    $s['weight_from'] = $s['weight_from'] ? (int)$s['weight_from'] : null;
    $s['weight_to'] = $s['weight_to'] ? (int)$s['weight_to'] : null;
}

if ($recommended) {
    $recommended['id'] = (int)$recommended['id'];
    $recommended['height_from'] = $recommended['height_from'] ? (int)$recommended['height_from'] : null;
    $recommended['height_to'] = $recommended['height_to'] ? (int)$recommended['height_to'] : null;
    $recommended['weight_from'] = $recommended['weight_from'] ? (int)$recommended['weight_from'] : null;
    $recommended['weight_to'] = $recommended['weight_to'] ? (int)$recommended['weight_to'] : null;
}

jsonResponse([
    'recommended' => $recommended,
    'sizes' => $sizes,
]);
