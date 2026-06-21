<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';
$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!empty($email) && !empty($password)) {
        
        // 1. KIỂM TRA ADMIN — credentials từ env hoặc database
        $adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@gmail.com';
        $adminPass  = getenv('ADMIN_PASSWORD') ?: '';
        if ($adminPass !== '') {
            $isAdmin = ($email === $adminEmail || $email === 'admin') && $password === $adminPass;
        } else {
            // Verify admin against database with password_verify
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (email = ? OR username = ?) AND role = 'admin' LIMIT 1");
            $stmt->execute([$email, $email]);
            $adminUser = $stmt->fetch();
            $isAdmin = $adminUser && password_verify($password, $adminUser['password']);
        }
        if ($isAdmin) {
            $_SESSION['user_id'] = 0; 
            $_SESSION['username'] = 'Admin Shop';
            $_SESSION['role'] = 'admin';
            $_SESSION['admin_logged_in'] = true;

            header("Location: admin/index.php");
            exit();
        } 
        
        // 2. KIỂM TRA KHÁCH HÀNG TRONG DATABASE
        else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                
                // MỚI: Kiểm tra xem tài khoản có bị khóa (status = 0) không
                if (isset($user['status']) && $user['status'] == 0) {
                    $error = "Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin!";
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = 'user';

                    // Generate + store API token for chatbot
                    $apiToken = bin2hex(random_bytes(32));
                    $stmt = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
                    $stmt->execute([$apiToken, $user['id']]);
                    $_SESSION['api_token'] = $apiToken;

                    header("Location: index.php");
                    exit();
                }
                
            } else {
                $error = "Tài khoản hoặc mật khẩu không chính xác!";
            }
        }
    }
}
include 'includes/header.php'; 
?>

<div class="auth-form" style="max-width: 400px; margin: 50px auto; padding: 30px; border: 1px solid #ddd; background: #fff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
    <h2 style="text-align: center; margin-bottom: 25px; font-weight: bold; text-transform: uppercase;">Đăng nhập</h2>

    <?php if (isset($_GET['register_success'])): ?>
        <p style="color: green; background: #efe; padding: 10px; text-align: center; border-radius: 4px;">Đăng ký thành công! Mời bạn đăng nhập.</p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p style="color: #d0021b; background: #fee; padding: 10px; text-align: center; border-radius: 4px;"><?= $error ?></p>
    <?php endif; ?>

    <form method="POST">
        <div style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom: 5px; font-weight:600;">Email hoặc Username Admin:</label>
            <input type="text" name="email" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
        </div>
        <div style="margin-bottom: 20px;">
            <label style="display:block; margin-bottom: 5px; font-weight:600;">Mật khẩu:</label>
            <input type="password" name="password" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
        </div>
        <button type="submit" style="width: 100%; padding: 12px; background: #000; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 16px;">ĐĂNG NHẬP</button>
    </form>
    
    <p style="text-align: center; margin-top: 15px; font-size: 14px; color: #666;">
        Chưa có tài khoản? <a href="register.php" style="color: #007bff; text-decoration: none;">Đăng ký ngay</a>
    </p>
</div>

<?php include 'includes/footer.php'; ?>