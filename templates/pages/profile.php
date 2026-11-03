
<div class="container" style="max-width: 1100px; margin: 40px auto; padding: 0 15px; font-family: 'Montserrat', sans-serif;">
    
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 40px;">
        <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-top: 4px solid #000;">
            <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 10px;">Thành viên</p>
            <h3 style="margin: 0; font-size: 20px;"><?= htmlspecialchars($username) ?></h3>
        </div>
        <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-top: 4px solid #d0021b;">
            <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 10px;">Đơn thành công</p>
            <h3 style="margin: 0; font-size: 20px;"><?= $stat['completed_count'] ?? 0 ?> đơn</h3>
        </div>
        <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-top: 4px solid #2ecc71;">
            <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 10px;">Tổng chi tiêu</p>
            <h3 style="margin: 0; font-size: 20px; color: #d0021b;"><?= number_format($stat['total_spent'] ?? 0) ?>đ</h3>
        </div>
    </div>

    <div style="display: flex; gap: 30px; border-bottom: 1px solid #eee; margin-bottom: 25px; padding-bottom: 10px;">
        <?php
        $tabs = [
            'all' => 'Tất cả đơn',
            'Chờ xử lý' => 'Chờ xử lý',
            'Đang giao' => 'Đang giao',
            'Đã hoàn thành' => 'Đã hoàn thành'
        ];

        foreach ($tabs as $key => $label): 
            $isActive = ($status_filter === $key);
        ?>
            <a href="profile.php?status=<?= urlencode($key) ?>" 
               style="text-decoration: none; 
                      color: <?= $isActive ? '#000' : '#999' ?>; 
                      font-weight: <?= $isActive ? '700' : '500' ?>; 
                      border-bottom: <?= $isActive ? '2px solid #000' : 'none' ?>; 
                      padding-bottom: 10px; 
                      transition: 0.3s;">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div style="background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.05);">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8f8f8; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;">
                    <th style="padding: 20px; text-align: left;">Mã đơn</th>
                    <th style="padding: 20px; text-align: left;">Ngày đặt</th>
                    <th style="padding: 20px; text-align: right;">Giá trị</th>
                    <th style="padding: 20px; text-align: center;">Trạng thái</th>
                    <th style="padding: 20px; text-align: center;">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($my_orders) > 0): ?>
                    <?php foreach ($my_orders as $o): ?>
                    <tr style="border-bottom: 1px solid #f1f1f1;">
                        <td style="padding: 20px; font-weight: 700;">#<?= $o['id'] ?></td>
                        <td style="padding: 20px; color: #666; font-size: 14px;"><?= date('d/m/Y', strtotime($o['created_at'])) ?></td>
                        <td style="padding: 20px; text-align: right; font-weight: 700; color: #000;"><?= number_format($o['total_price']) ?>đ</td>
                        <td style="padding: 20px; text-align: center;">
                            <?php 
                                $bg = "#eee"; $cl = "#777";
                                if($o['status'] == 'Chờ xử lý') { $bg = "#fff9db"; $cl = "#f59f00"; }
                                elseif($o['status'] == 'Đang giao') { $bg = "#e7f5ff"; $cl = "#228be6"; }
                                elseif($o['status'] == 'Đã hoàn thành') { $bg = "#ebfbee"; $cl = "#40c057"; }
                            ?>
                            <span style="background: <?= $bg ?>; color: <?= $cl ?>; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;">
                                <?= $o['status'] ?>
                            </span>
                        </td>
                        <td style="padding: 20px; text-align: center;">
                            <a href="order_details.php?id=<?= $o['id'] ?>" style="text-decoration: none; border: 1px solid #000; color: #000; padding: 8px 15px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                CHI TIẾT
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="padding: 40px; text-align: center; color: #999;">
                            Không có đơn hàng nào trong mục này.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin: 40px 0 15px; font-size: 18px;">Yêu cầu hỗ trợ</h2>
    <div style="background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.05);">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8f8f8; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;">
                    <th style="padding: 20px; text-align: left;">Mã</th>
                    <th style="padding: 20px; text-align: left;">Loại</th>
                    <th style="padding: 20px; text-align: left;">Đơn</th>
                    <th style="padding: 20px; text-align: left;">Nội dung</th>
                    <th style="padding: 20px; text-align: left;">Ngày tạo</th>
                    <th style="padding: 20px; text-align: center;">Trạng thái</th>
                    <th style="padding: 20px; text-align: left;">Phản hồi của shop</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($tickets)): ?>
                    <?php foreach ($tickets as $t): ?>
                    <tr style="border-bottom: 1px solid #f1f1f1;">
                        <td style="padding: 20px; font-weight: 700;">#<?= (int) $t['id'] ?></td>
                        <td style="padding: 20px; font-size: 14px;"><?= htmlspecialchars((string) $t['type_label'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding: 20px; font-size: 14px;"><?= $t['order_id'] !== null ? '#' . (int) $t['order_id'] : '-' ?></td>
                        <td style="padding: 20px; color: #666; font-size: 14px;"><?= htmlspecialchars((string) $t['summary'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding: 20px; color: #666; font-size: 14px;"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $t['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding: 20px; text-align: center;">
                            <span style="background: #eee; color: #555; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;">
                                <?= htmlspecialchars((string) $t['status_label'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td style="padding: 20px; color: #666; font-size: 14px;"><?= htmlspecialchars((string) ($t['admin_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="padding: 40px; text-align: center; color: #999;">
                            Bạn chưa có yêu cầu hỗ trợ nào. Hãy nhờ trợ lý chat để gửi yêu cầu cho shop.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    $ticketLink = static function (int $n) use ($status_filter): string {
        $query = (is_string($status_filter) && $status_filter !== 'all') ? ['status=' . urlencode($status_filter)] : [];
        $query[] = 'ticket_page=' . $n;
        return 'profile.php?' . htmlspecialchars(implode('&', $query), ENT_QUOTES, 'UTF-8');
    };
    ?>
    <p style="margin-top: 15px; display: flex; gap: 15px; align-items: center; font-size: 14px;">
        <?php if ($ticket_page > 1): ?><a href="<?= $ticketLink($ticket_page - 1) ?>" style="color: #000;">Trước</a><?php endif; ?>
        <span>Trang <?= (int) $ticket_page ?>/<?= (int) $ticket_pages ?></span>
        <?php if ($ticket_page < $ticket_pages): ?><a href="<?= $ticketLink($ticket_page + 1) ?>" style="color: #000;">Sau</a><?php endif; ?>
    </p>

    <div style="margin-top: 30px; text-align: center;">
        <a href="index.php" style="color: #666; text-decoration: none; font-size: 14px;">← Tiếp tục mua sắm</a>
    </div>
</div>

