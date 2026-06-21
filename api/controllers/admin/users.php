<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

requireAdmin();

global $pdo;
$stmt = $pdo->query("SELECT id, username, email, role, status, created_at FROM users ORDER BY id DESC");
$users = $stmt->fetchAll();

foreach ($users as &$u) {
    $u['id'] = (int)$u['id'];
    $u['status'] = (int)$u['status'];
}

jsonResponse(['users' => $users]);
