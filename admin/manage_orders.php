<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Kiểm tra quyền Admin dựa trên logic session của bạn
if (!isset($_SESSION['admin_logged_in']) && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin')) { 
    header("Location: login.php"); 
    exit(); 
}

require_once '../config/db.php';

// 1. XỬ LÝ CẬP NHẬT TRẠNG THÁI
if (isset($_POST['update_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = $_POST['status'];
    
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $order_id]);
    header("Location: manage_orders.php?msg=updated");
    exit();
}

// 2. LẤY DANH SÁCH ĐƠN HÀNG (Kèm thông tin người dùng nếu cần)
$stmt = $pdo->query("SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC");
$orders = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý đơn hàng</title>
    <style>
        body { font-family: 'Segoe UI', Arial; display: flex; margin: 0; background: #f4f4f4; }
        .sidebar { width: 250px; background: #2c3e50; color: white; min-height: 100vh; padding: 20px; position: fixed; }
        .sidebar a { display: block; color: #fff; text-decoration: none; padding: 12px; margin-bottom: 5px; }
        .main-content { margin-left: 270px; padding: 30px; flex: 1; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; }
        th, td { padding: 15px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #000; color: white; }
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .status-pending { background: #ffeaa7; color: #d63031; } /* Chờ xử lý */
        .status-shipping { background: #81ecec; color: #0984e3; } /* Đang giao */
        .status-completed { background: #55efc4; color: #00b894; } /* Đã giao */
        .btn-update { background: #2d3436; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body>

<div class="sidebar">
    <h3>FASHION ADMIN</h3>
    <a href="index.php">Dashboard</a>
    <a href="manage_products.php">Quản lý Sản phẩm</a>
    <a href="manage_orders.php" style="background:#34495e;">Quản lý Đơn hàng</a>
    <a href="manage_users.php">Quản lý Người dùng</a>
    <a href="logout.php" style="color:#ff4757; margin-top:50px;">Đăng xuất</a>
</div>

<div class="main-content">
    <h1>Danh sách đơn hàng</h1>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Khách hàng</th>
                <th>Tổng tiền</th>
                <th>Ngày đặt</th>
                <th>Trạng thái</th>
                <th>Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
            <tr>
                <td>#<?= $order['id'] ?></td>
                <td><strong><?= htmlspecialchars($order['username']) ?></strong></td>
                <td><?= number_format($order['total_price']) ?>đ</td>
                <td><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></td>
                <td>
                    <?php 
                        $status_class = '';
                        if($order['status'] == 'Chờ xử lý') $status_class = 'status-pending';
                        elseif($order['status'] == 'Đang giao') $status_class = 'status-shipping';
                        elseif($order['status'] == 'Đã hoàn thành') $status_class = 'status-completed';
                    ?>
                    <span class="status-badge <?= $status_class ?>"><?= $order['status'] ?></span>
                </td>
                <td>
                    <form method="POST" style="display: flex; gap: 5px;">
                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                        <select name="status" style="padding: 5px; border-radius: 4px;">
                            <option value="Chờ xử lý" <?= $order['status'] == 'Chờ xử lý' ? 'selected' : '' ?>>Chờ xử lý</option>
                            <option value="Đang giao" <?= $order['status'] == 'Đang giao' ? 'selected' : '' ?>>Đang giao</option>
                            <option value="Đã hoàn thành" <?= $order['status'] == 'Đã hoàn thành' ? 'selected' : '' ?>>Đã hoàn thành</option>
                            <option value="Đã hủy" <?= $order['status'] == 'Đã hủy' ? 'selected' : '' ?>>Đã hủy</option>
                        </select>
                        <button type="submit" name="update_status" class="btn-update">Lưu</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>