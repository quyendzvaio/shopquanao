<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Vui lòng đăng nhập để mua hàng!'); window.location.href='login.php';</script>";
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $product_id = (int) $_POST['product_id'];
    $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1;

    $action = $_POST['action'] ?? 'add';

    // Kiểm tra stock
    $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $stock = (int)$stmt->fetchColumn();
    if ($stock <= 0) {
        echo "<script>alert('Sản phẩm đã hết hàng!'); window.location.href='product.php?id=$product_id';</script>";
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$user_id, $product_id]);
        $item = $stmt->fetch();

        if ($item) {
            $new_qty = $item['quantity'] + $quantity;
            $update = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
            $update->execute([$new_qty, $item['id']]);
        } else {
            $insert = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
            $insert->execute([$user_id, $product_id, $quantity]);
        }

        // CODE MỚI THÊM: Kiểm tra nếu là MUA NGAY thì đi tới checkout, còn lại đi tới giỏ hàng
        if ($action === 'buy_now') {
            header("Location: checkout.php");
        } else {
            header("Location: cart.php");
        }
        if (isset($_POST['action']) && $_POST['action'] === 'buy_now') {
            header("Location: checkout.php"); // Đi thẳng tới thanh toán
        } else {
            header("Location: cart.php"); // Về giỏ hàng
        }
        exit();

    } catch (PDOException $e) {
        die("Lỗi hệ thống: " . $e->getMessage());
    }
}