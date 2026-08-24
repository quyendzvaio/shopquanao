<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';
require_once __DIR__ . '/../../services/CartService.php';

$user = authenticate();
$userId = (int)$user['id'];
$data = getJsonInput();

global $pdo;
try {
    jsonResponse((new CartService($pdo))->add($userId, $data), 201);
} catch (InvalidArgumentException $error) {
    errorResponse($error->getMessage(), 400);
} catch (RuntimeException $error) {
    errorResponse($error->getMessage(), str_contains($error->getMessage(), 'not found') ? 404 : 409);
}
