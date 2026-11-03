<?php
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$filters = ['open' => 'Đang mở', 'all' => 'Tất cả'];
foreach (SupportTicketService::STATUSES as $key => $label) {
    $filters[$key] = $label;
}
$pageLink = static function (int $n) use ($filter, $e): string {
    $query = ($filter === 'open' ? [] : ['status=' . $filter]);
    $query[] = 'page=' . $n;
    return 'manage_tickets.php?' . $e(implode('&', $query));
};
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Yêu cầu hỗ trợ</title>
<style>
body { font-family: 'Segoe UI', Arial; display: flex; margin: 0; background: #f4f4f4; }
.sidebar { width: 250px; background: #2c3e50; color: white; min-height: 100vh; padding: 20px; position: fixed; }
.sidebar a { display: block; color: #fff; text-decoration: none; padding: 12px; margin-bottom: 5px; }
.main-content { margin-left: 270px; padding: 30px; flex: 1; }
table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; }
th, td { padding: 15px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
th { background: #000; color: white; }
.status-badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; background: #dfe6e9; color: #2d3436; }
.status-moi { background: #ffeaa7; color: #d63031; }
.status-dang_xu_ly { background: #81ecec; color: #0984e3; }
.status-da_xong { background: #55efc4; color: #00b894; }
.filters a { display: inline-block; padding: 6px 12px; margin-right: 6px; border-radius: 20px; background: #fff; color: #2d3436; text-decoration: none; font-size: 13px; }
.filters a.active { background: #2d3436; color: #fff; }
.pager { margin-top: 15px; display: flex; gap: 15px; align-items: center; font-size: 14px; }
.pager a { color: #2d3436; }
.btn-update { background: #2d3436; color: white; border: none; padding: 8px 14px; cursor: pointer; border-radius: 4px; }
.panel { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.notice { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; }
</style>
</head>
<body>

<div class="sidebar">
    <h3>FASHION ADMIN</h3>
    <a href="index.php">Dashboard</a>
    <a href="manage_products.php">Quản lý Sản phẩm</a>
    <a href="manage_orders.php">Quản lý Đơn hàng</a>
    <a href="manage_tickets.php" style="background:#34495e;">Yêu cầu hỗ trợ</a>
    <a href="manage_users.php">Quản lý Người dùng</a>
    <a href="logout.php" style="color:#ff4757; margin-top:50px;">Đăng xuất</a>
</div>

<div class="main-content">
    <h1>Yêu cầu hỗ trợ</h1>

    <?php if (($msg ?? null) === 'updated'): ?>
        <div class="notice">Đã cập nhật yêu cầu.</div>
    <?php endif; ?>

    <?php if ($ticket !== null): ?>
    <div class="panel">
        <h2>Yêu cầu #<?= (int) $ticket['id'] ?></h2>
        <p><strong>Khách hàng:</strong> <?= $e($ticket['username'] ?? '') ?></p>
        <p><strong>Loại:</strong> <?= $e($ticket['type_label']) ?></p>
        <p><strong>Đơn hàng:</strong>
            <?php if ($ticket['order_id'] !== null): ?>
                <a href="manage_orders.php">#<?= (int) $ticket['order_id'] ?></a>
            <?php else: ?>
                -
            <?php endif; ?>
        </p>
        <p><strong>Nội dung:</strong> <?= nl2br($e($ticket['summary'])) ?></p>
        <p><strong>Ngày tạo:</strong> <?= $e(date('d/m/Y H:i', strtotime((string) $ticket['created_at']))) ?></p>
        <p><strong>Trạng thái hiện tại:</strong>
            <span class="status-badge status-<?= $e($ticket['status']) ?>"><?= $e($ticket['status_label']) ?></span>
        </p>
        <form method="POST" action="manage_tickets.php">
            <?php include __DIR__ . '/../../partials/csrf_field.php'; ?>
            <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
            <p>
                <select name="status" style="padding: 5px; border-radius: 4px;">
                    <?php foreach (SupportTicketService::STATUSES as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= $ticket['status'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p><textarea name="admin_note" rows="4" maxlength="2000" style="width:100%; padding:8px; box-sizing:border-box;" placeholder="Ghi chú cho khách"><?= $e($ticket['admin_note'] ?? '') ?></textarea></p>
            <button type="submit" name="update_ticket" value="1" class="btn-update">Lưu</button>
        </form>
        <p><a href="manage_tickets.php">&larr; Về danh sách</a></p>
    </div>
    <?php endif; ?>

    <p class="filters">
        <?php foreach ($filters as $key => $label): ?>
            <a href="manage_tickets.php<?= $key === 'open' ? '' : '?status=' . $e($key) ?>" class="<?= $filter === $key ? 'active' : '' ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Khách hàng</th>
                <th>Loại</th>
                <th>Đơn</th>
                <th>Nội dung</th>
                <th>Ngày tạo</th>
                <th>Trạng thái</th>
                <th>Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tickets as $t): ?>
            <tr>
                <td>#<?= (int) $t['id'] ?></td>
                <td><strong><?= $e($t['username'] ?? '') ?></strong></td>
                <td><?= $e($t['type_label']) ?></td>
                <td><?= $t['order_id'] !== null ? '#' . (int) $t['order_id'] : '-' ?></td>
                <td><?= $e($t['summary']) ?></td>
                <td><?= $e(date('d/m/Y H:i', strtotime((string) $t['created_at']))) ?></td>
                <td><span class="status-badge status-<?= $e($t['status']) ?>"><?= $e($t['status_label']) ?></span></td>
                <td><a href="manage_tickets.php?id=<?= (int) $t['id'] ?>">Xử lý</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($tickets === []): ?>
            <tr><td colspan="8" style="text-align:center; color:#999;">Không có yêu cầu nào trong mục này.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <p class="pager">
        <?php if ($page > 1): ?><a href="<?= $pageLink($page - 1) ?>">Trước</a><?php endif; ?>
        <span>Trang <?= (int) $page ?>/<?= (int) $pages ?></span>
        <?php if ($page < $pages): ?><a href="<?= $pageLink($page + 1) ?>">Sau</a><?php endif; ?>
    </p>
</div>

</body>
</html>
