
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Sửa sản phẩm</title>
    <style>
        body { font-family: Arial; background: #f4f4f4; padding: 40px; }
        .edit-box { max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        input, textarea { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .btn-save { background: #27ae60; color: white; border: none; padding: 12px; width: 100%; cursor: pointer; font-weight: bold; }
        .back-link { display: block; margin-top: 15px; text-align: center; color: #666; text-decoration: none; }
    </style>
</head>
<body>

<div class="edit-box">
    <h2>Chỉnh sửa sản phẩm #<?= $id ?></h2>
    <form method="POST" enctype="multipart/form-data">
        <?php include __DIR__ . '/../../partials/csrf_field.php'; ?>
        <label>Tên sản phẩm:</label>
        <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>

        <label>Giá tiền (VNĐ):</label>
        <input type="number" name="price" value="<?= $product['price'] ?>" required>

        <label>Số lượng trong kho:</label>
        <input type="number" name="stock" value="<?= (int)$product['stock'] ?>" min="0" required>

        <label>Mô tả:</label>
        <textarea name="description" rows="4"><?= htmlspecialchars($product['description']) ?></textarea>

        <label>Danh mục:</label>
        <select name="category_id" id="category_id" required>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int)$category['id'] ?>" <?= (int)$product['category_id'] === (int)$category['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Danh mục con:</label>
        <select name="subcategory_id" id="subcategory_id">
            <option value="">Không có danh mục con</option>
            <?php foreach ($subcategories as $subcategory): ?>
                <option value="<?= (int)$subcategory['id'] ?>" data-category="<?= (int)$subcategory['category_id'] ?>" <?= (int)($product['subcategory_id'] ?? 0) === (int)$subcategory['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($subcategory['category_name'] . ' — ' . $subcategory['display_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Ảnh hiện tại:</label><br>
        <img src="/assets/images/<?= $product['image'] ?>" width="100" style="margin: 10px 0; border: 1px solid #ddd;"><br>
        
        <label>Thay ảnh mới (để trống nếu giữ nguyên):</label>
        <input type="file" name="image">

        <?php $currentTags = StyleTag::parseList($product['style_tags'] ?? ''); ?>
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
        <button type="submit" name="update_product" class="btn-save">CẬP NHẬT THAY ĐỔI</button>
        <a href="manage_products.php" class="back-link">← Quay lại danh sách</a>
    </form>
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
