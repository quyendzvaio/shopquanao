<?php

requireAdmin();

global $pdo;

$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$orders        = new OrderService($pdo);
$totalOrders   = $orders->count();
$totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$totalRevenue = $orders->completedRevenue();

jsonResponse([
    'dashboard' => [
        'total_products' => $totalProducts,
        'total_orders'   => $totalOrders,
        'total_users'    => $totalUsers,
        'total_revenue'  => $totalRevenue,
    ],
]);
