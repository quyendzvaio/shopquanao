<?php

requireAdmin();

global $pdo, $route_params;
$id = (int)($route_params['id'] ?? 0);
if (!$id) errorResponse('Product ID required', 400);

$data = getJsonInput();

try {
    (new ProductAdminService($pdo))->update($id, $data);
} catch (ApiError $error) {
    errorResponse($error->getMessage(), $error->getCode());
}

jsonResponse(['message' => 'Product updated']);
