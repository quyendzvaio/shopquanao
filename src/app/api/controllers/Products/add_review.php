<?php

$user = authenticate();

global $pdo, $route_params;
$productId = (int)($route_params['id'] ?? 0);

if (!$productId) errorResponse('Product ID required', 400);

$data = getJsonInput();
$rating  = (int)($data['rating'] ?? 0);
$comment = trim($data['comment'] ?? '');

if ($rating < 1 || $rating > 5) errorResponse('Rating must be 1-5', 400);
if (!$comment) errorResponse('Comment is required', 400);

try {
    (new ReviewService($pdo))->add($productId, (int)$user['id'], $rating, $comment);
} catch (ApiError $error) {
    errorResponse($error->getMessage(), $error->getCode());
}

jsonResponse(['message' => 'Review submitted successfully'], 201);
