<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Same admin check as manage_products.php: stop before any upload or insert.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../../src/bootstrap.php';

$msg_error = '';
if (isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $price = $_POST['price'];
    $category_id = (int)($_POST['category_id'] ?? 0);
    $image_name = (string)($_FILES['image']['name'] ?? '');
    $error = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;

    // Same whitelist + server-generated name as manage_products.php: the
    // client-supplied file name never reaches the web root.
    $img_ex_lc = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
    $allowed_exs = array("jpg", "jpeg", "png", "webp", "gif");
    if ($error !== UPLOAD_ERR_OK) {
        $msg_error = "Lỗi khi tải file lên: " . $error;
    } elseif (!in_array($img_ex_lc, $allowed_exs, true)) {
        $msg_error = "Định dạng file này không được hỗ trợ (Chỉ nhận jpg, png, webp, gif).";
    } else {
        $new_img_name = uniqid("IMG-", true) . '.' . $img_ex_lc;
        // Tải ảnh vào thư mục assets/images/
        if (move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../assets/images/' . $new_img_name)) {
            $stmt = $pdo->prepare("INSERT INTO products (name, price, category_id, image, style_tags) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $price, $category_id > 0 ? $category_id : null, $new_img_name, StyleTag::toColumn((array)($_POST['style_tags'] ?? []))]);
            header("Location: manage_products.php");
            exit();
        }
        $msg_error = "Không thể di chuyển file vào thư mục images.";
    }
}
?>
<div style="padding:20px;">
    <h2>Thêm sản phẩm mới</h2>
    <?php if ($msg_error !== ''): ?><p style="color: #c0392b;"><?= htmlspecialchars($msg_error) ?></p><?php endif; ?>
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