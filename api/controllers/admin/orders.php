<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

requireAdmin();

global $pdo;
$stmt = $pdo->query("SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC");
$orders = $stmt->fetchAll();

foreach ($orders as &$o) {
    $o['id']         = (int)$o['id'];
    $o['user_id']    = (int)$o['user_id'];
    $o['total_price'] = (float)$o['total_price'];
}

jsonResponse(['orders' => $orders]);
