<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    header("Location: login.php");
    exit();
}

$order_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Bảo mật: Chỉ lấy đơn hàng nếu nó thuộc về user đang đăng nhập
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if (!$order) { die("Đơn hàng không tồn tại hoặc bạn không có quyền xem."); }

// Lấy chi tiết các món hàng (Cần bảng order_details)
$stmt_items = $pdo->prepare("
    SELECT od.*, p.name, p.image 
    FROM order_details od 
    JOIN products p ON od.product_id = p.id 
    WHERE od.order_id = ?
");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Chi tiết đơn hàng #<?= $order_id ?></title>
    <style>
        .box { max-width: 800px; margin: 30px auto; border: 1px solid #ddd; padding: 20px; }
        .item { display: flex; align-items: center; border-bottom: 1px solid #eee; padding: 10px 0; }
        .item img { margin-right: 20px; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Đơn hàng #<?= $order_id ?></h2>
        <p>Trạng thái: <strong><?= $order['status'] ?></strong></p>
        <hr>
        <?php foreach ($items as $item): ?>
            <div class="item">
                <img src="images/<?= $item['image'] ?>" width="80">
                <div>
                    <h4><?= htmlspecialchars($item['name']) ?></h4>
                    <p>Số lượng: <?= $item['quantity'] ?> x <?= number_format($item['price']) ?>đ</p>
                </div>
            </div>
        <?php endforeach; ?>
        <h3 style="text-align: right;">Tổng thanh toán: <?= number_format($order['total_price']) ?>đ</h3>
        <a href="profile.php">← Quay lại Lịch sử mua hàng</a>
    </div>
</body>
</html>