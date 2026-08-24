<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';
require_once __DIR__ . '/../../services/OrderService.php';

$user = authenticate();
$userId = (int)$user['id'];

$statusFilter = $_GET['status'] ?? 'all';

global $pdo;
jsonResponse((new OrderService($pdo))->list($userId, $statusFilter));
