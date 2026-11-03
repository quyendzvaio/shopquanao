<?php

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);

if (!$id) errorResponse('Product ID required', 400);

$sizes = (new SizeService($pdo))->productSizes($id);

jsonResponse(['sizes' => $sizes]);
