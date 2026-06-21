<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();

// Generate token on login — already stored. Logout: nullify.
global $pdo;
$stmt = $pdo->prepare("UPDATE users SET api_token = NULL WHERE id = ?");
$stmt->execute([$user['id']]);

jsonResponse(['message' => 'Logged out successfully']);
