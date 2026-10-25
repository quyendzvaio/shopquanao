
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý người dùng - Admin</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; display: flex; background: #f4f7f6; }
        .sidebar { width: 250px; background: #34495e; color: white; min-height: 100vh; padding: 20px; position: fixed; }
        .sidebar a { display: block; color: #bdc3c7; text-decoration: none; padding: 12px; border-bottom: 1px solid #444; }
        .sidebar a:hover { background: #2c3e50; color: white; }
        .main-content { margin-left: 290px; padding: 30px; flex: 1; }
        .user-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .user-table th, .user-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .user-table th { background: #000; color: white; text-transform: uppercase; font-size: 13px; }
        .btn { padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; display: inline-block; font-weight: bold; color: white; }
        .btn-lock { background: #e67e22; } /* Màu cam cho nút Khóa */
        .btn-unlock { background: #27ae60; } /* Màu xanh cho nút Mở khóa */
        .btn-role { background: #2f3542; margin-right: 5px; }
        .status-tag { font-size: 12px; font-weight: bold; }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>ADMIN PANEL</h2>
    <a href="index.php">🏠 Tổng quan</a>
    <a href="manage_products.php">👕 Quản lý sản phẩm</a>
    <a href="manage_orders.php">📦 Quản lý đơn hàng</a>
    <a href="manage_users.php" style="color: white; background: #2c3e50;">👥 Quản lý người dùng</a>
    <a href="../logout.php" style="color: #e74c3c; margin-top: 50px;">🔴 Đăng xuất</a>
</div>

<div class="main-content">
    <h1>Quản lý thành viên</h1>

    <?php if (isset($msg)): ?>
        <div style="padding: 10px; margin-bottom: 20px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px;">
            <?php
                if($msg == 'locked') echo 'Đã khóa tài khoản thành công.';
                if($msg == 'unlocked') echo 'Đã mở khóa tài khoản thành công.';
                if($msg == 'updated') echo 'Đã cập nhật quyền thành công.';
            ?>
        </div>
    <?php endif; ?>

    <table class="user-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Tên người dùng</th>
                <th>Email</th>
                <th>Quyền</th>
                <th>Trạng thái</th>
                <th>Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr style="<?= ($user['status'] == 0) ? 'background: #fffafa;' : '' ?>">
                <td>#<?= $user['id'] ?></td>
                <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td>
                    <span style="font-size: 11px; font-weight: bold; padding: 3px 8px; border-radius: 4px; color: white; background: <?= ($user['role'] == 'admin') ? '#e74c3c' : '#3498db' ?>;">
                        <?= strtoupper($user['role']) ?>
                    </span>
                </td>
                <td>
                    <?= ($user['status'] == 1) 
                        ? '<span class="status-tag" style="color: green;">● Hoạt động</span>' 
                        : '<span class="status-tag" style="color: red;">● Đã khóa</span>' 
                    ?>
                </td>
                <td>
                    <?php if ($user['id'] != $session['user_id']): ?>
                        <a href="?id=<?= $user['id'] ?>&toggle_role=<?= ($user['role'] == 'admin') ? 'user' : 'admin' ?>" class="btn btn-role">Đổi quyền</a>
                        
                        <?php if ($user['status'] == 1): ?>
                            <a href="?action=lock&id=<?= $user['id'] ?>" 
                               class="btn btn-lock" 
                               onclick="return confirm('Bạn có chắc chắn muốn KHÓA tài khoản này?')">
                               Khóa
                            </a>
                        <?php else: ?>
                            <a href="?action=unlock&id=<?= $user['id'] ?>" class="btn btn-unlock">
                               Mở khóa
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color: #999; font-style: italic;">Đang online</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
