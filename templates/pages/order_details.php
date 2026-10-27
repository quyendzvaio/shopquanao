
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Chi tiết đơn hàng #<?= $order_id ?></title>
    <style>
        .box { max-width: 800px; margin: 30px auto; border: 1px solid #ddd; padding: 20px; }
        .item { display: flex; align-items: center; border-bottom: 1px solid #eee; padding: 10px 0; }
        .item img { margin-right: 20px; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Đơn hàng #<?= $order_id ?></h2>
        <p>Trạng thái: <strong><?= $order['status'] ?></strong></p>
        <hr>
        <?php foreach ($items as $item): ?>
            <div class="item">
                <img src="/assets/images/<?= $item['image'] ?>" width="80">
                <div>
                    <h4><?= htmlspecialchars($item['name']) ?></h4>
                    <p>Số lượng: <?= $item['quantity'] ?> x <?= number_format($item['price']) ?>đ</p>
                </div>
            </div>
        <?php endforeach; ?>
        <h3 style="text-align: right;">Tổng thanh toán: <?= number_format($order['total_price']) ?>đ</h3>
        <a href="profile.php">← Quay lại Lịch sử mua hàng</a>
    </div>
</body>
</html>