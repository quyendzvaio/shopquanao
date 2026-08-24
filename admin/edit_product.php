<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: ../login.php"); exit(); }
require_once '../config/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Lấy thông tin sản phẩm hiện tại
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) { die("Sản phẩm không tồn tại!"); }
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY id")->fetchAll();
$subcategories = $pdo->query(
    "SELECT sc.id, sc.category_id, sc.display_name, c.name AS category_name
     FROM product_subcategories sc JOIN categories c ON c.id = sc.category_id ORDER BY c.id, sc.id"
)->fetchAll();

// XỬ LÝ CẬP NHẬT
if (isset($_POST['update_product'])) {
    $name = $_POST['name'];
    $price = $_POST['price'];
    $stock = (int)($_POST['stock'] ?? 0);
    $description = $_POST['description'];
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $subcategoryId = (int)($_POST['subcategory_id'] ?? 0);
    $new_image = $_FILES['image']['name'];
    
    if (!empty($new_image)) {
        // Nếu người dùng chọn ảnh mới
        $img_ex = strtolower(pathinfo($new_image, PATHINFO_EXTENSION));
        $allowed_exs = array("jpg", "jpeg", "png", "webp", "gif");

        if (in_array($img_ex, $allowed_exs)) {
            $unique_name = uniqid("IMG-", true) . '.' . $img_ex;
            $target = "../images/" . $unique_name;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                // Xóa ảnh cũ trong thư mục cho sạch máy
                if (file_exists("../images/" . $product['image'])) {
                    unlink("../images/" . $product['image']);
                }
                $image_to_save = $unique_name;
            }
        }
    } else {
        // Nếu không chọn ảnh mới, giữ lại tên ảnh cũ
        $image_to_save = $product['image'];
    }

    $stmt = $pdo->prepare("UPDATE products
        SET name=?, price=?, stock=?, description=?, category_id=?, subcategory_id=?, image=? WHERE id=?");
    if ($stmt->execute([
        $name, $price, $stock, $description,
        $categoryId > 0 ? $categoryId : null,
        $subcategoryId > 0 ? $subcategoryId : null,
        $image_to_save, $id,
    ])) {
        header("Location: manage_products.php?msg=updated");
        exit();
    }
}
?>

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
        <img src="../images/<?= $product['image'] ?>" width="100" style="margin: 10px 0; border: 1px solid #ddd;"><br>
        
        <label>Thay ảnh mới (để trống nếu giữ nguyên):</label>
        <input type="file" name="image">

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
