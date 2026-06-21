<?php
session_start();
require_once '../config/db.php';
if (!isset($_SESSION['admin_logged_in'])) header("Location: login.php");

if (isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $price = $_POST['price'];
    $category_id = $_POST['category_id'];
    $image = $_FILES['image']['name'];
    
    // Tải ảnh vào thư mục images/
    move_uploaded_file($_FILES['image']['tmp_name'], "../images/".$image);

    $stmt = $pdo->prepare("INSERT INTO products (name, price, category_id, image) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $price, $category_id, $image]);
    header("Location: manage_products.php");
}
?>
<div style="padding:20px;">
    <h2>Thêm sản phẩm mới</h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="text" name="name" placeholder="Tên sản phẩm" required><br><br>
        <input type="number" name="price" placeholder="Giá" required><br><br>
        <select name="category_id">
            <option value="1">Áo</option>
            <option value="2">Quần</option>
        </select><br><br>
        <input type="file" name="image" required><br><br>
        <button type="submit" name="add_product">Lưu sản phẩm</button>
    </form>
</div>