<?php
require_once 'config/db.php';
include 'includes/header.php';

// 1. Lấy từ khóa tìm kiếm và danh mục từ URL
$search = $_GET['search'] ?? '';
$cat_id = $_GET['category'] ?? '';

// 2. Truy vấn lấy danh mục để hiển thị bộ lọc
$stmt_cats = $pdo->query("SELECT * FROM categories");
$categories = $stmt_cats->fetchAll();

// 3. Xây dựng câu lệnh SQL lấy sản phẩm (kèm tìm kiếm và lọc danh mục)
$sql = "SELECT * FROM products WHERE name LIKE ?";
$params = ["%$search%"];

if ($cat_id) {
    $sql .= " AND category_id = ?";
    $params[] = $cat_id;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
?>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 20px;">

    <h2 style="text-align: center; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 30px;">
        Danh sách sản phẩm
    </h2>

    <div class="filters" style="text-align: center; margin-bottom: 40px;">
        <a href="index.php" class="<?= !$cat_id ? 'active-filter' : '' ?>"
            style="text-decoration: none; color: #333; padding: 8px 20px; border: 1px solid #eee; margin: 0 5px; border-radius: 20px; display: inline-block;">
            Tất cả
        </a>
        <?php foreach ($categories as $cat): ?>
            <a href="index.php?category=<?= $cat['id'] ?>"
                style="text-decoration: none; color: #333; padding: 8px 20px; border: 1px solid #eee; margin: 0 5px; border-radius: 20px; display: inline-block;">
                <?= $cat['name'] ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="product-grid">
        <?php if (count($products) > 0): ?>
            <?php foreach ($products as $p): ?>
                <div class="product-card">
                    <a href="product.php?id=<?= $p['id']; ?>">
                        <img src="images/<?= $p['image']; ?>" alt="<?= $p['name']; ?>">
                    </a>

                    <div class="product-info">
                        <div class="product-name"><?= $p['name']; ?></div>
                        <div class="product-price"><?= number_format($p['price']); ?>đ</div>
                        <?php $outOfStock = (int)($p['stock'] ?? 0) <= 0; ?>
                        <p style="font-size: 12px; color: <?= $outOfStock ? '#e74c3c' : '#27ae60' ?>; margin: 5px 0;">
                            <?= $outOfStock ? 'HẾT HÀNG' : 'Còn ' . (int)$p['stock'] . ' sản phẩm' ?>
                        </p>

                        <form action="add_to_cart.php" method="POST" style="margin-top: 10px;">
                            <input type="hidden" name="product_id" value="<?= $p['id']; ?>">
                            <input type="hidden" name="quantity" value="1">

                            <div style="display: flex; gap: 4px;">
                                <?php if ($outOfStock): ?>
                                    <span style="flex:1; padding:8px 2px; font-size:11px; text-align:center; border:1px solid #ccc; background:#eee; color:#aaa; font-weight:bold; border-radius:2px;">HẾT HÀNG</span>
                                    <span style="flex:1; padding:8px 2px; font-size:11px; text-align:center; border:1px solid #ccc; background:#eee; color:#aaa; font-weight:bold; border-radius:2px;">HẾT HÀNG</span>
                                <?php else: ?>
                                    <button type="submit" name="action" value="add"
                                        style="flex:1; padding:8px 2px; font-size:11px; cursor:pointer; border:1px solid #000; background:#fff; color:#000; font-weight:bold; border-radius:2px;">
                                        GIỎ HÀNG
                                    </button>
                                    <button type="submit" name="action" value="buy_now"
                                        style="flex:1; padding:8px 2px; font-size:11px; cursor:pointer; background:#d0021b; border:1px solid #d0021b; color:#fff; font-weight:bold; border-radius:2px;">
                                        MUA NGAY
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align: center; width: 100%; grid-column: 1 / -1;">Không tìm thấy sản phẩm nào.</p>
        <?php endif; ?>
    </div>

</div>

<?php include 'includes/footer.php'; ?>