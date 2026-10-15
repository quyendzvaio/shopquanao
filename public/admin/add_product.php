<?php
session_start();
require_once __DIR__ . '/../../src/bootstrap.php';
if (!isset($_SESSION['admin_logged_in'])) header("Location: login.php");

if (isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $price = $_POST['price'];
    $category_id = $_POST['category_id'];
    $image = $_FILES['image']['name'];
    
    // Tải ảnh vào thư mục assets/images/
    move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../assets/images/'.$image);

    $stmt = $pdo->prepare("INSERT INTO products (name, price, category_id, image, style_tags) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $price, $category_id, $image, StyleTag::toColumn((array)($_POST['style_tags'] ?? []))]);
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
        <?php $currentTags = []; ?>
        <fieldset style="margin: 10px 0;">
            <legend>Tag dịp / phong cách (tối đa <?= StyleTag::MAX_PER_PRODUCT ?>)</legend>
            <?php foreach (StyleTag::ALL as $tag): ?>
                <label style="margin-right: 12px;">
                    <input type="checkbox" name="style_tags[]" value="<?= $tag ?>"
                        <?= in_array($tag, $currentTags, true) ? 'checked' : '' ?>>
                    <?= htmlspecialchars(StyleTag::label($tag)) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit" name="add_product">Lưu sản phẩm</button>
    </form>
</div>