<?php
session_start();
require_once 'config/db.php';

$cart_id = $_GET['id'] ?? null;
$value = $_GET['value'] ?? null;
$type = $_GET['type'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if ($cart_id && $value && $user_id) {
    if ($type == 'qty') {
        $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE cart SET size = ? WHERE id = ? AND user_id = ?");
    }
    $stmt->execute([$value, $cart_id, $user_id]);
}

header("Location: cart.php");
exit();