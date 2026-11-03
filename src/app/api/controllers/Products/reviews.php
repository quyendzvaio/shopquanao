<?php

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);

if (!$id) errorResponse('Product ID required', 400);

$reviews = (new ReviewService($pdo))->forProductWithUsername($id);

foreach ($reviews as &$r) {
    $r['id']    = (int)$r['id'];
    $r['rating'] = (int)$r['rating'];
}

jsonResponse(['reviews' => $reviews]);
