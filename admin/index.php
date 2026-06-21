<?php
session_start();

// Kiểm tra quyền đăng nhập Admin
if (!isset($_SESSION['admin_logged_in']) && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin')) {
    header("Location: login.php");
    exit();
}

require_once '../config/db.php';

try {
    // 1. Đếm tổng số sản phẩm
    $total_products = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

    // 2. Đếm tổng số đơn hàng
    $total_orders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

    // 3. Đếm tổng số thành viên
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    // 4. TÍNH TỔNG DOANH THU (Chỉ tính những đơn hàng đã hoàn thành để thực tế hơn)
    // Nếu bạn muốn tính tất cả đơn hàng, hãy bỏ đoạn: WHERE status = 'Đã hoàn thành'
    $stmt_revenue = $pdo->query("SELECT SUM(total_price) FROM orders WHERE status = 'Đã hoàn thành'");
    $total_revenue = $stmt_revenue->fetchColumn() ?: 0; // Nếu chưa có tiền thì hiện số 0

} catch (PDOException $e) {
    die("Lỗi kết nối dữ liệu: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div style="display:flex; min-height:100vh;">
        <div style="width:250px; background:#2c3e50; color:#fff; padding:20px;">
            <h3>FASHION ADMIN</h3>
            <ul style="list-style:none; padding:0; margin-top:30px;">
                <li style="margin-bottom:15px;"><a href="index.php" style="color:#fff; text-decoration:none;">Dashboard</a></li>
                <li style="margin-bottom:15px;"><a href="manage_products.php" style="color:#fff; text-decoration:none;">Quản lý Sản phẩm</a></li>
                <li style="margin-bottom:15px;"><a href="manage_orders.php" style="color:#fff; text-decoration:none;">Quản lý Đơn hàng</a></li>
                <li style="margin-bottom:15px;"><a href="manage_users.php" style="color:#fff; text-decoration:none;">Quản lý Người dùng</a></li>
                <li style="margin-top:50px;"><a href="logout.php" style="color:#ff4757; text-decoration:none;">Đăng xuất</a></li>
            </ul>
        </div>
        
        <div style="flex:1; padding:30px; background:#f9f9f9;">
            <h1>Tổng quan hệ thống</h1>
            
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:20px; margin-top:20px;">
                <div style="background:#fff; padding:20px; border-radius:8px; border-left:5px solid #3498db; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <h4 style="margin:0; color:#666;">Sản phẩm</h4>
                    <p style="font-size:24px; font-weight:bold; margin:10px 0;"><?= $total_products ?></p>
                </div>
                <div style="background:#fff; padding:20px; border-radius:8px; border-left:5px solid #2ecc71; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <h4 style="margin:0; color:#666;">Đơn hàng</h4>
                    <p style="font-size:24px; font-weight:bold; margin:10px 0;"><?= $total_orders ?></p>
                </div>
                <div style="background:#fff; padding:20px; border-radius:8px; border-left:5px solid #f1c40f; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <h4 style="margin:0; color:#666;">Thành viên</h4>
                    <p style="font-size:24px; font-weight:bold; margin:10px 0;"><?= $total_users ?></p>
                </div>
            </div>

            <div style="margin-top:20px;">
                <div style="background:#fff; padding:30px; border-radius:8px; border-left:5px solid #e74c3c; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <h4 style="margin:0; color:#666; text-transform: uppercase; letter-spacing: 1px;">Tổng doanh thu (Đã hoàn thành)</h4>
                    <p style="font-size:40px; font-weight:bold; margin:15px 0 0 0; color:#e74c3c;">
                        <?= number_format($total_revenue, 0, ',', '.') ?> <span style="font-size: 20px;">VNĐ</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>