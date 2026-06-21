<?php
// 1. Khởi động session nếu chưa có
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Kết nối database để lấy số lượng giỏ hàng thực tế
require_once 'config/db.php';

$total_items = 0;
if (isset($_SESSION['user_id'])) {
    // Truy vấn tổng số lượng của tất cả sản phẩm trong giỏ của User này
    $stmt_count = $pdo->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = ?");
    $stmt_count->execute([$_SESSION['user_id']]);
    $row = $stmt_count->fetch();
    
    $total_items = $row['total'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>FASHION SHOP - Đẳng Cấp Thời Trang</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Montserrat', sans-serif; }
    </style>
</head>

<body>
<header style="background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 15px 0;">
    <div class="container" style="display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto; padding: 0 15px;">
        
        <a href="index.php" class="logo" style="text-decoration: none; color: #000; font-weight: 800; font-size: 26px; letter-spacing: 1px; flex: 1;">
            FASHION<span style="font-weight: 300; color: #d0021b;">SHOP</span>
        </a>

        <div class="search-bar" style="flex: 2; display: flex; justify-content: center;">
            <form action="index.php" method="GET" style="display: flex; width: 80%; max-width: 400px; position: relative;">
                <input type="text" name="search" placeholder="Bạn đang tìm gì..."
                    style="padding: 12px 20px; border: 1px solid #eee; border-radius: 30px; outline: none; width: 100%; font-size: 14px; background: #f8f8f8;">
                <button type="submit"
                    style="position: absolute; right: 5px; top: 5px; padding: 7px 15px; background: #000; color: #fff; border: none; border-radius: 25px; cursor: pointer;">
                    <i class="fa fa-search"></i>
                </button>
            </form>
        </div>

        <nav class="nav-menu" style="display: flex; align-items: center; gap: 25px; flex: 2; justify-content: flex-end;">
            
            <a href="cart.php" style="text-decoration: none; color: #333; font-weight: 600; font-size: 13px; text-transform: uppercase; display: flex; align-items: center; gap: 8px;">
                <div style="position: relative;">
                    <i class="fa-solid fa-bag-shopping" style="font-size: 20px;"></i>
                    <span style="position: absolute; top: -8px; right: -10px; background: #d0021b; color: #fff; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                        <?= $total_items ?>
                    </span>
                </div>
                Giỏ hàng
            </a>

            <?php if (isset($_SESSION['user_id'])): ?>
                <div style="height: 20px; width: 1px; background: #eee;"></div> <div style="display: flex; flex-direction: column; align-items: flex-start;">
                    <span style="font-size: 11px; color: #999; text-transform: uppercase;">Tài khoản</span>
                    <a href="profile.php" style="text-decoration: none; color: #000; font-weight: 700; font-size: 14px;">
                        <?= $_SESSION['username'] ?>
                    </a>
                </div>

                <a href="profile.php" style="text-decoration: none; color: #333; font-weight: 600; font-size: 13px; text-transform: uppercase; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-clock-rotate-left"></i> Đơn hàng
                </a>

                <a href="logout.php" title="Đăng xuất" style="text-decoration: none; color: #999; font-size: 18px;">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>

            <?php else: ?>
                <a href="login.php" style="text-decoration: none; color: #333; font-weight: 600; font-size: 13px; text-transform: uppercase;">Đăng nhập</a>
                <a href="register.php" style="text-decoration: none; background: #000; color: #fff; padding: 10px 25px; border-radius: 2px; font-weight: 600; font-size: 12px; text-transform: uppercase;">Đăng ký</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
    <main></main>