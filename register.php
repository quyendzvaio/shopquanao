<?php
require_once 'config/db.php';
include 'includes/header.php';

$error = "";
// Khởi tạo biến success là rỗng để tránh lỗi Warning
$success = ""; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($password !== $confirm_password) {
        $error = "Mật khẩu xác nhận không khớp!";
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->rowCount() > 0) {
            $error = "Email này đã được đăng ký!";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $apiToken = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, api_token) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$fullname, $email, $hashed_password, $apiToken])) {
                header("Location: login.php?register_success=1");
                exit(); 
            } else {
                $error = "Có lỗi xảy ra, vui lòng thử lại.";
            }
        }
    }
}
?>

<div class="auth-form" style="max-width: 400px; margin: 50px auto; padding: 20px; border: 1px solid #ddd; background: #fff;">
    <h2 style="text-align: center;">Đăng ký tài khoản</h2>
    
    <?php if(!empty($error)): ?>
        <p style="color: red; background: #fee; padding: 10px;"><?= $error ?></p>
    <?php endif; ?>
    
    <form method="POST">
        <div style="margin-bottom: 15px;">
            <label>Họ tên:</label>
            <input type="text" name="fullname" required style="width: 100%; padding: 8px; margin-top: 5px;">
        </div>
        <div style="margin-bottom: 15px;">
            <label>Email:</label>
            <input type="email" name="email" required style="width: 100%; padding: 8px; margin-top: 5px;">
        </div>
        <div style="margin-bottom: 15px;">
            <label>Mật khẩu:</label>
            <input type="password" name="password" required style="width: 100%; padding: 8px; margin-top: 5px;">
        </div>
        <div style="margin-bottom: 15px;">
            <label>Xác nhận mật khẩu:</label>
            <input type="password" name="confirm_password" required style="width: 100%; padding: 8px; margin-top: 5px;">
        </div>
        <button type="submit" class="btn" style="width: 100%; padding: 10px; background-color: #28a745; color: white; border: none; cursor: pointer;">Đăng ký ngay</button>
    </form>
</div>

<?php include 'includes/footer.php'; ?>