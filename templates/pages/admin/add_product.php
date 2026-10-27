<div style="padding:20px;">
    <h2>Thêm sản phẩm mới</h2>
    <?php if ($msg_error !== ''): ?><p style="color: #c0392b;"><?= htmlspecialchars($msg_error) ?></p><?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
        <?php include __DIR__ . '/../../partials/csrf_field.php'; ?>
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