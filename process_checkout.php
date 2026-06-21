<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$full_name = $_POST['full_name'];
$phone = $_POST['phone'];
$address = $_POST['address'];
$note = $_POST['note'] ?? '';

try {
    $pdo->beginTransaction();

    // 1. Lấy giỏ hàng + kiểm tra stock từng sản phẩm
    $stmt = $pdo->prepare("SELECT c.product_id, c.quantity FROM cart c WHERE c.user_id = ?");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll();

    foreach ($cart_items as $item) {
        $p_stmt = $pdo->prepare("SELECT stock FROM products WHERE id = ?");
        $p_stmt->execute([$item['product_id']]);
        $stock = (int)$p_stmt->fetchColumn();
        if ($stock <= 0) {
            throw new Exception("Sản phẩm ID {$item['product_id']} đã hết hàng!");
        }
    }

    // 2. Tính tổng tiền
    $total_price = 0;
    foreach ($cart_items as $item) {
        $p_stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $p_stmt->execute([$item['product_id']]);
        $price = $p_stmt->fetchColumn();
        $total_price += $price * $item['quantity'];
    }

    // 3. Tạo đơn hàng
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_price, status, created_at) VALUES (?, ?, 'pending', NOW())");
    $stmt->execute([$user_id, $total_price]);
    $order_id = $pdo->lastInsertId();

    // 4. Chuyển từng món từ giỏ hàng sang order_items
    foreach ($cart_items as $item) {
        $p_stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $p_stmt->execute([$item['product_id']]);
        $current_price = $p_stmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$order_id, $item['product_id'], $item['quantity'], $current_price]);
    }

    // 5. Xóa giỏ hàng
    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->execute([$user_id]);

    $pdo->commit();
    echo "<script>alert('Đặt hàng thành công! Fashion Shop sẽ liên hệ bạn sớm nhất.'); window.location.href='index.php';</script>";

} catch (Exception $e) {
    $pdo->rollBack();
    die("Lỗi đặt hàng: " . $e->getMessage());
}
