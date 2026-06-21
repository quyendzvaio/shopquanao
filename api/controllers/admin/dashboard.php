<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

requireAdmin();

global $pdo;

$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalOrders   = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$stmt = $pdo->query("SELECT SUM(total_price) FROM orders WHERE status = 'Đã hoàn thành'");
$totalRevenue = (float)($stmt->fetchColumn() ?: 0);

jsonResponse([
    'dashboard' => [
        'total_products' => $totalProducts,
        'total_orders'   => $totalOrders,
        'total_users'    => $totalUsers,
        'total_revenue'  => $totalRevenue,
    ],
]);
