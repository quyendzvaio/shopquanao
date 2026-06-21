<?php
session_start();
require_once 'config/db.php';
include 'includes/header.php';

// 1. Lấy ID sản phẩm từ URL và định nghĩa biến để tránh lỗi Undefined
$product_id = $_GET['id'] ?? 0;

// 2. Truy vấn thông tin sản phẩm
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    echo "<div class='container'><h3>Sản phẩm không tồn tại!</h3></div>";
    include 'includes/footer.php';
    exit;
}

// 3. Lấy danh sách Size của sản phẩm này
$stmt_size = $pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
$stmt_size->execute([$product_id]);
$sizes = $stmt_size->fetchAll();

// 4. Lấy danh sách Đánh giá
$stmt_rev = $pdo->prepare("SELECT r.*, u.username FROM reviews r JOIN users u ON r.id = u.id WHERE r.product_id = ? ORDER BY r.created_at DESC");
$stmt_rev->execute([$product_id]);
$reviews = $stmt_rev->fetchAll();
?>

<div class="container"
    style="max-width: 1200px; margin: 40px auto; padding: 0 15px; font-family: 'Montserrat', sans-serif;">
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 50px; align-items: start;">

        <div class="product-image">
            <img src="images/<?= $product['image'] ?>" alt="<?= $product['name'] ?>"
                style="width: 100%; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
        </div>

        <div class="product-details">
            <h1 style="font-size: 32px; margin-bottom: 15px; text-transform: uppercase;"><?= $product['name'] ?></h1>
            <p style="font-size: 24px; color: #d0021b; font-weight: 700; margin-bottom: 20px;">
                <?= number_format($product['price']) ?>đ</p>

            <?php $outOfStock = (int)($product['stock'] ?? 0) <= 0; ?>
            <p style="font-size: 14px; font-weight: bold; padding: 8px 15px; border-radius: 4px; margin-bottom: 20px; display: inline-block; background: <?= $outOfStock ? '#ffeaa7' : '#d4edda' ?>; color: <?= $outOfStock ? '#d63031' : '#155724' ?>;">
                <?= $outOfStock ? '❌ HẾT HÀNG — Sản phẩm tạm thời không có sẵn' : '✅ Còn ' . (int)$product['stock'] . ' sản phẩm trong kho' ?>
            </p>

            <div style="border-top: 1px solid #eee; padding-top: 20px; color: #666; font-size: 14px; line-height: 1.6;">
                <p><strong>Chất liệu:</strong> <?= $product['material'] ?? 'Vải cao cấp, thoáng mát' ?></p>
                <p><strong>Mô tả:</strong> <?= $product['description'] ?></p>
            </div>

            <form action="add_to_cart.php" method="POST" style="margin-top: 30px;">
                <input type="hidden" name="product_id" value="<?= $product_id ?>">

                <div style="margin-bottom: 25px;">
                    <div style="margin-bottom: 25px;">
                        <?php if (count($sizes) > 0): ?>
                            <p style="font-weight: 700; margin-bottom: 12px; font-size: 13px; text-transform: uppercase;">
                                Chọn kích thước:</p>
                            <div style="display: flex; gap: 10px;">
                                <?php foreach ($sizes as $s): ?>
                                    <label style="cursor: pointer;">
                                        <input type="radio" name="selected_size" value="<?= $s['size_name'] ?>" required
                                            style="display: none;" class="size-radio">
                                        <span class="size-item"
                                            style="display: inline-block; padding: 10px 20px; border: 1px solid #ddd; font-weight: bold; font-size: 12px; transition: 0.3s;">
                                            <?= $s['size_name'] ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="selected_size" value="Freesize">
                        <?php endif; ?>
                    </div>
                    <div style="margin-bottom: 30px;">
                        <p style="font-weight: 700; margin-bottom: 12px; font-size: 13px; text-transform: uppercase;">Số
                            lượng:</p>
                        <input type="number" name="quantity" value="1" min="1"
                            style="width: 80px; padding: 10px; border: 1px solid #ddd; text-align: center; font-weight: bold;">
                    </div>

                    <div style="display: flex; gap: 15px;">
                        <button type="submit" name="action" value="add"
                            <?= $outOfStock ? 'disabled' : '' ?>
                            style="flex: 1; padding: 15px; background: <?= $outOfStock ? '#eee' : '#fff' ?>; border: 2px solid <?= $outOfStock ? '#ccc' : '#000' ?>; font-weight: 700; cursor: <?= $outOfStock ? 'not-allowed' : 'pointer' ?>; transition: 0.3s; color: <?= $outOfStock ? '#aaa' : '#000' ?>;">
                            <?= $outOfStock ? 'HẾT HÀNG' : 'THÊM VÀO GIỎ' ?>
                        </button>
                        <button type="submit" name="action" value="buy_now"
                            <?= $outOfStock ? 'disabled' : '' ?>
                            style="flex: 1; padding: 15px; background: <?= $outOfStock ? '#ccc' : '#000' ?>; color: <?= $outOfStock ? '#999' : '#fff' ?>; border: 2px solid <?= $outOfStock ? '#ccc' : '#000' ?>; font-weight: 700; cursor: <?= $outOfStock ? 'not-allowed' : 'pointer' ?>; transition: 0.3s;">
                            <?= $outOfStock ? 'HẾT HÀNG' : 'MUA NGAY' ?>
                        </button>
                    </div>
            </form>
        </div>
    </div>

    <div style="margin-top: 80px; border-top: 1px solid #eee; padding-top: 50px;">
        <h3 style="text-transform: uppercase; letter-spacing: 1px; text-align: center; margin-bottom: 40px;">Đánh giá từ
            khách hàng</h3>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 50px;">
            <div>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <form action="submit_review.php" method="POST"
                        style="background: #f9f9f9; padding: 25px; border-radius: 8px;">
                        <input type="hidden" name="product_id" value="<?= $product_id ?>">
                        <p style="font-weight: bold; margin-bottom: 15px;">Gửi nhận xét của bạn</p>
                        <select name="rating"
                            style="width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ddd;">
                            <option value="5">★★★★★ (Tuyệt vời)</option>
                            <option value="4">★★★★☆ (Rất tốt)</option>
                            <option value="3">★★★☆☆ (Bình thường)</option>
                            <option value="2">★★☆☆☆ (Kém)</option>
                            <option value="1">★☆☆☆☆ (Rất tệ)</option>
                        </select>
                        <textarea name="comment" placeholder="Bạn thấy sản phẩm này thế nào?..." required
                            style="width: 100%; height: 100px; padding: 12px; border: 1px solid #ddd; margin-bottom: 15px; font-family: inherit;"></textarea>
                        <button type="submit"
                            style="width: 100%; background: #d0021b; color: #fff; border: none; padding: 12px; font-weight: bold; cursor: pointer;">GỬI
                            ĐÁNH GIÁ</button>
                    </form>
                <?php else: ?>
                    <p style="text-align: center; background: #fff9db; padding: 20px; border-radius: 5px;">
                        Vui lòng <a href="login.php" style="color: #000; font-weight: 700;">đăng nhập</a> để để lại đánh
                        giá.
                    </p>
                <?php endif; ?>
            </div>

            <div style="max-height: 500px; overflow-y: auto;">
                <?php if (count($reviews) > 0): ?>
                    <?php foreach ($reviews as $r): ?>
                        <div style="margin-bottom: 25px; border-bottom: 1px solid #eee; padding-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between;">
                                <strong><?= htmlspecialchars($r['username']) ?></strong>
                                <span style="color: #f1c40f;"><?= str_repeat('★', $r['rating']) ?></span>
                            </div>
                            <p style="color: #555; margin: 10px 0; font-size: 14px;">
                                <?= nl2br(htmlspecialchars($r['comment'])) ?></p>
                            <small style="color: #999;"><?= date('d/m/Y', strtotime($r['created_at'])) ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: #999; margin-top: 40px;">Chưa có đánh giá nào cho sản phẩm này.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .size-radio:checked+.size-item {
        background: #000;
        color: #fff;
        border-color: #000;
    }

    .size-item:hover {
        border-color: #000;
    }
</style>

<?php include 'includes/footer.php'; ?>