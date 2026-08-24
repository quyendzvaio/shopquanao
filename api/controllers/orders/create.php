<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';
require_once __DIR__ . '/../../services/OrderService.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo;
try {
    jsonResponse((new OrderService($pdo))->create($userId), 201);
} catch (RuntimeException $error) {
    errorResponse($error->getMessage(), str_contains($error->getMessage(), 'empty') ? 400 : 409);
}
