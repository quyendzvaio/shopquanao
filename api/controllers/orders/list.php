<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
$userId = (int)$user['id'];

$statusFilter = $_GET['status'] ?? 'all';

global $pdo;

$sql = "SELECT * FROM orders WHERE user_id = ?";
$params = [$userId];

if ($statusFilter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

foreach ($orders as &$o) {
    $o['id']       = (int)$o['id'];
    $o['total_price'] = (float)$o['total_price'];
}

// Stats
$stmt = $pdo->prepare("SELECT SUM(total_price) as total_spent, COUNT(*) as completed_count FROM orders WHERE user_id = ? AND status = 'Đã hoàn thành'");
$stmt->execute([$userId]);
$stats = $stmt->fetch();

jsonResponse([
    'orders' => $orders,
    'stats' => [
        'total_spent'   => (float)($stats['total_spent'] ?? 0),
        'completed_count' => (int)($stats['completed_count'] ?? 0),
    ],
]);
