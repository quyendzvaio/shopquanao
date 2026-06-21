<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Kiểm tra quyền Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { 
    header("Location: ../login.php"); 
    exit(); 
}

require_once '../config/db.php';

// 2. XỬ LÝ THÊM SẢN PHẨM MỚI (Hỗ trợ nhiều định dạng ảnh)
if (isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $price = $_POST['price'];
    $stock = (int)($_POST['stock'] ?? 0);
    $description = $_POST['description'];
    
    $image_name = $_FILES['image']['name'];
    $tmp_name = $_FILES['image']['tmp_name'];
    $error = $_FILES['image']['error'];

    if ($error === 0) {
        $img_ex = pathinfo($image_name, PATHINFO_EXTENSION); // Lấy đuôi file
        $img_ex_lc = strtolower($img_ex); // Chuyển về chữ thường

        // Cho phép nhiều định dạng khác nhau
        $allowed_exs = array("jpg", "jpeg", "png", "webp", "gif"); 

        if (in_array($img_ex_lc, $allowed_exs)) {
            // Tạo tên file mới tránh trùng lặp và lỗi ký tự lạ
            $new_img_name = uniqid("IMG-", true) . '.' . $img_ex_lc;
            $img_upload_path = '../images/' . $new_img_name;
            
            if (move_uploaded_file($tmp_name, $img_upload_path)) {
                $stmt = $pdo->prepare("INSERT INTO products (name, price, stock, description, image) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $price, $stock, $description, $new_img_name]);
                header("Location: manage_products.php?msg=added");
                exit();
            } else {
                $msg_error = "Không thể di chuyển file vào thư mục images.";
            }
        } else {
            $msg_error = "Định dạng file này không được hỗ trợ (Chỉ nhận jpg, png, webp, gif).";
        }
    } else {
        $msg_error = "Lỗi khi tải file lên: " . $error;
    }
}

// 3. XỬ LÝ XÓA SẢN PHẨM
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $img_name = $stmt->fetchColumn();
    
    if ($img_name && file_exists("../images/" . $img_name)) {
        unlink("../images/" . $img_name);
    }

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: manage_products.php?msg=deleted");
    exit();
}

$products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý sản phẩm</title>
    <style>
        body { font-family: 'Segoe UI', Arial; display: flex; margin: 0; background: #f4f4f4; }
        .sidebar { width: 250px; background: #34495e; color: white; min-height: 100vh; padding: 20px; position: fixed; }
        .sidebar a { display: block; color: #bdc3c7; text-decoration: none; padding: 12px; border-bottom: 1px solid #444; }
        .main-content { margin-left: 270px; padding: 20px; flex: 1; }
        .form-container { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: center; }
        th { background: #000; color: white; }
        .btn { padding: 6px 12px; border-radius: 4px; text-decoration: none; color: white; font-size: 13px; font-weight: bold; border: none; cursor: pointer; }
        .btn-add { background: #27ae60; }
        .btn-del { background: #e74c3c; }
        .btn-edit { background: #3498db; }
        img { object-fit: cover; border-radius: 4px; border: 1px solid #eee; }
        .error-msg { color: #e74c3c; background: #fdeaea; padding: 10px; border-radius: 4px; margin-bottom: 10px; }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>ADMIN PANEL</h2>
    <a href="index.php">🏠 Tổng quan</a>
    <a href="manage_products.php" style="background:#2c3e50; color:white;">👕 Quản lý sản phẩm</a>
    <a href="manage_orders.php">📦 Quản lý đơn hàng</a>
    <a href="manage_users.php">👥 Quản lý người dùng</a>
    <a href="../logout.php" style="color: #e74c3c; margin-top: 50px;">🔴 Đăng xuất</a>
</div>

<div class="main-content">
    <h1>Quản lý sản phẩm</h1>

    <?php if(isset($msg_error)): ?>
        <div class="error-msg"><?= $msg_error ?></div>
    <?php endif; ?>

    <div class="form-container">
        <h3>Thêm sản phẩm mới</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="text" name="name" placeholder="Tên sản phẩm" required style="padding: 8px; width: 250px;">
            <input type="number" name="price" placeholder="Giá tiền" required style="padding: 8px; width: 150px;">
            <input type="number" name="stock" placeholder="Số lượng" value="0" min="0" style="padding: 8px; width: 80px;">
            <input type="file" name="image" required style="padding: 8px;">
            <br><br>
            <textarea name="description" placeholder="Mô tả sản phẩm" style="width: 100%; height: 60px; padding: 8px;"></textarea>
            <br><br>
            <button type="submit" name="add_product" class="btn btn-add">LƯU SẢN PHẨM</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Ảnh</th>
                <th>Tên sản phẩm</th>
                <th>Giá</th>
                <th>Kho</th>
                <th>Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $p): ?>
            <tr>
                <td>#<?= $p['id'] ?></td>
                <td>
                    <?php 
                        $imgPath = "../images/" . $p['image'];
                        if (!empty($p['image']) && file_exists($imgPath)): 
                    ?>
                        <img src="<?= $imgPath ?>" width="60" height="60">
                    <?php else: ?>
                        <div style="font-size: 10px; color: #999;">Không có ảnh<br>(<?= htmlspecialchars($p['image']) ?>)</div>
                    <?php endif; ?>
                </td>
                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                <td><?= number_format($p['price']) ?>đ</td>
                <td style="color: <?= ($p['stock'] ?? 0) <= 0 ? '#e74c3c' : '#27ae60' ?>; font-weight: bold;">
                    <?= (int)($p['stock'] ?? 0) ?>
                </td>
                <td>
                    <a href="edit_product.php?id=<?= $p['id'] ?>" class="btn btn-edit">Sửa</a>
                    <a href="?delete_id=<?= $p['id'] ?>" class="btn btn-del" onclick="return confirm('Xóa sản phẩm này?')">Xóa</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>