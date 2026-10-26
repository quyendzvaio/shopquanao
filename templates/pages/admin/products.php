
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
            <select name="category_id" id="category_id" required style="padding: 8px;">
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int)$category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="subcategory_id" id="subcategory_id" style="padding: 8px;">
                <option value="">Không có danh mục con</option>
                <?php foreach ($subcategories as $subcategory): ?>
                    <option value="<?= (int)$subcategory['id'] ?>" data-category="<?= (int)$subcategory['category_id'] ?>">
                        <?= htmlspecialchars($subcategory['category_name'] . ' — ' . $subcategory['display_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="file" name="image" required style="padding: 8px;">
            <br><br>
            <textarea name="description" placeholder="Mô tả sản phẩm" style="width: 100%; height: 60px; padding: 8px;"></textarea>
            <br><br>
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
                        $imgPath = '/assets/images/' . $p['image'];
                        if (!empty($p['image']) && file_exists($imagesDir . $p['image'])): 
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

<script>
const categorySelect = document.getElementById('category_id');
const subcategorySelect = document.getElementById('subcategory_id');
function syncSubcategories() {
    const categoryId = categorySelect.value;
    for (const option of subcategorySelect.options) {
        option.disabled = option.dataset.category && option.dataset.category !== categoryId;
    }
    if (subcategorySelect.selectedOptions[0]?.disabled) subcategorySelect.value = '';
}
categorySelect.addEventListener('change', syncSubcategories);
syncSubcategories();
</script>

</body>
</html>
