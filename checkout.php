<?php
require_once 'config/db.php';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Lấy thông tin giỏ hàng để hiển thị lại
$stmt = $pdo->prepare("
    SELECT p.name, p.price, c.quantity, (p.price * c.quantity) as subtotal 
    FROM cart c JOIN products p ON c.product_id = p.id 
    WHERE c.user_id = ?
");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

if (count($cart_items) == 0) {
    echo "<div class='container' style='margin-top:50px;'>Giỏ hàng của bạn đang trống. <a href='index.php'>Quay lại mua sắm</a></div>";
    include 'includes/footer.php';
    exit();
}

$total_money = 0;
foreach($cart_items as $item) $total_money += $item['subtotal'];
?>

<div class="container" style="max-width: 1200px; margin: 40px auto; display: flex; gap: 30px; padding: 0 15px;">
    <div style="flex: 2; background: #f9f9f9; padding: 30px; border-radius: 8px;">
        <h2 style="margin-bottom: 20px; text-transform: uppercase;">Thông tin giao hàng</h2>
        <form action="process_checkout.php" method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Họ và tên *</label>
                <input type="text" name="full_name" required style="width: 100%; padding: 10px; border: 1px solid #ddd;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Số điện thoại *</label>
                <input type="text" name="phone" required style="width: 100%; padding: 10px; border: 1px solid #ddd;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Địa chỉ nhận hàng *</label>
                <textarea name="address" required style="width: 100%; padding: 10px; border: 1px solid #ddd; height: 100px;"></textarea>
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px;">Ghi chú đơn hàng</label>
                <textarea name="note" style="width: 100%; padding: 10px; border: 1px solid #ddd; height: 60px;"></textarea>
            </div>
            <button type="submit" class="btn-black" style="width: 100%; padding: 15px; font-size: 18px; margin-top: 20px;">XÁC NHẬN ĐẶT HÀNG</button>
        </form>
    </div>

    <div style="flex: 1; border: 1px solid #ddd; padding: 20px; border-radius: 8px; height: fit-content;">
        <h3 style="border-bottom: 1px solid #eee; padding-bottom: 10px;">Đơn hàng của bạn</h3>
        <div style="margin: 20px 0;">
            <?php foreach($cart_items as $item): ?>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px;">
                    <span><?= $item['name'] ?> x <?= $item['quantity'] ?></span>
                    <span><?= number_format($item['subtotal']) ?>đ</span>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="border-top: 2px solid #333; padding-top: 15px; display: flex; justify-content: space-between; font-weight: bold; font-size: 18px;">
            <span>TỔNG CỘNG:</span>
            <span style="color: #ff4757;"><?= number_format($total_money) ?>đ</span>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>