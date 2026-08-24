<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';
require_once __DIR__ . '/../../services/CartService.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo;
jsonResponse((new CartService($pdo))->list($userId));
