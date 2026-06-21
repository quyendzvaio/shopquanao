<?php
session_start();
require_once 'config/db.php';

// Kiểm tra nếu người dùng đã đăng nhập và gửi form qua phương thức POST
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $product_id = $_POST['product_id'];
    $rating = $_POST['rating'];
    $comment = trim($_POST['comment']);

    if (!empty($comment) && $rating >= 1 && $rating <= 5) {
        try {
            // Chèn đánh giá mới vào bảng reviews
            $stmt = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
            $stmt->execute([$product_id, $user_id, $rating, $comment]);

            // Thông báo thành công và quay lại trang sản phẩm
            header("Location: product.php?id=" . $product_id . "&msg=success");
            exit();
        } catch (PDOException $e) {
            die("Lỗi hệ thống: " . $e->getMessage());
        }
    }
}

// Nếu có lỗi hoặc truy cập trái phép, quay lại trang chủ
header("Location: index.php");
exit();