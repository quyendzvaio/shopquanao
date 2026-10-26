
<div class="container" style="max-width: 1100px; margin: 20px auto; padding: 20px; font-family: Arial, sans-serif;">
    <h2 style="border-bottom: 2px solid #333; padding-bottom: 10px;">Giỏ hàng của bạn</h2>
    
    <form action="checkout.php" method="POST" id="cart-form">
        <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
            <thead style="background: #f4f4f4;">
                <tr>
                    <th style="padding: 10px; border: 1px solid #ddd;">Chọn</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Ảnh</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Sản phẩm</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Kích cỡ</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Số lượng</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Tổng</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total = 0;
                if (count($cart_items) > 0):
                    foreach($cart_items as $item):
                        $total += $item['subtotal'];
                ?>
                <tr style="text-align: center;">
                    <td style="padding: 10px; border: 1px solid #ddd;">
                        <input type="checkbox" name="selected_items[]" value="<?= $item['cart_id'] ?>" checked>
                    </td>
                    <td style="padding: 10px; border: 1px solid #ddd;">
                        <img src="/assets/images/<?= $item['image'] ?>" width="60" style="border-radius: 4px;">
                    </td>
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;"><?= $item['name'] ?></td>
                    
                    <td style="padding: 10px; border: 1px solid #ddd;">
                        <select onchange="updateCart(<?= $item['cart_id'] ?>, this.value, 'size')" style="padding: 5px;">
                            <option value="S" <?= $item['size'] == 'S' ? 'selected' : '' ?>>S</option>
                            <option value="M" <?= $item['size'] == 'M' ? 'selected' : '' ?>>M</option>
                            <option value="L" <?= $item['size'] == 'L' ? 'selected' : '' ?>>L</option>
                            <option value="XL" <?= $item['size'] == 'XL' ? 'selected' : '' ?>>XL</option>
                            <option value="Freesize" <?= strcasecmp((string) $item['size'], 'Freesize') === 0 ? 'selected' : '' ?>>Freesize</option>
                        </select>
                    </td>

                    <td style="padding: 10px; border: 1px solid #ddd;">
                        <input type="number" value="<?= $item['quantity'] ?>" min="1" 
                               onchange="updateCart(<?= $item['cart_id'] ?>, this.value, 'qty')"
                               style="width: 50px; padding: 5px; text-align: center;">
                    </td>

                    <td style="padding: 10px; border: 1px solid #ddd; color: #d0021b; font-weight: bold;"><?= number_format($item['subtotal']) ?>đ</td>
                    <td style="padding: 10px; border: 1px solid #ddd;">
                        <button type="button" onclick="if (confirm('Xóa món này?')) removeCartLine(<?= $item['cart_id'] ?>)" style="color: red; background: none; border: none; padding: 0; font: inherit; cursor: pointer;">Xóa</button>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="7" style="padding: 20px; text-align: center;">Giỏ hàng trống! <a href="index.php">Mua sắm ngay</a></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="text-align: right; margin-top: 20px;">
            <h3 style="margin-bottom: 20px;">Tổng tiền dự kiến: <span style="color: #d0021b; font-size: 28px;"><?= number_format($total) ?>đ</span></h3>
            <a href="index.php" style="padding: 10px 20px; background: #6c757d; color: #fff; text-decoration: none; border-radius: 4px; margin-right: 10px;">Tiếp tục mua hàng</a>
            <button type="submit" style="padding: 10px 25px; background: #28a745; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Thanh toán sản phẩm đã chọn</button>
        </div>
    </form>

<!-- State changes are POSTs with the CSRF token; these forms sit outside #cart-form (forms cannot nest). -->
<form id="update-cart-form" action="update_cart.php" method="POST" style="display: none;">
    <?php include __DIR__ . '/../partials/csrf_field.php'; ?>
    <input type="hidden" name="id" value="">
    <input type="hidden" name="value" value="">
    <input type="hidden" name="type" value="">
</form>
<form id="remove-cart-form" action="cart.php" method="POST" style="display: none;">
    <?php include __DIR__ . '/../partials/csrf_field.php'; ?>
    <input type="hidden" name="remove" value="">
</form>
</div>

<script>
function updateCart(cartId, value, type) {
    var form = document.getElementById('update-cart-form');
    form.elements['id'].value = cartId;
    form.elements['value'].value = value;
    form.elements['type'].value = type;
    form.submit();
}
function removeCartLine(cartId) {
    var form = document.getElementById('remove-cart-form');
    form.elements['remove'].value = cartId;
    form.submit();
}
</script>

