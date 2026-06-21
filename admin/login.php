<?php
/**
 * Admin Login — xác thực qua database hoặc env.
 * Không hardcode credentials.
 */
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';

if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1. Thử admin credentials từ env (production)
    $adminUser = getenv('ADMIN_USER') ?: '';
    $adminPass = getenv('ADMIN_PASSWORD') ?: '';
    if ($adminUser !== '' && $adminPass !== '') {
        if ($username === $adminUser && $password === $adminPass) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['role'] = 'admin';
            $_SESSION['username'] = 'Admin Shop';
            header('Location: index.php');
            exit();
        }
    }

    // 2. Thử xác thực qua database (users table với role=admin)
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'admin' LIMIT 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['role'] = 'admin';
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: index.php');
            exit();
        }
    } catch (Exception $e) {
        // DB not available
    }

    $error = 'Sai tài khoản hoặc mật khẩu!';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body style="display:flex; justify-content:center; align-items:center; height:100vh; background:#f4f4f4;">
    <form method="POST" style="background:#fff; padding:30px; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.1); width:300px;">
        <h2 style="text-align:center;">ADMIN LOGIN</h2>
        <?php if ($error): ?>
            <p style="color:red; font-size:13px; text-align:center;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <input type="text" name="username" placeholder="Username" required style="width:100%; padding:10px; margin:10px 0; border:1px solid #ddd;">
        <input type="password" name="password" placeholder="Password" required style="width:100%; padding:10px; margin:10px 0; border:1px solid #ddd;">
        <button type="submit" name="login" style="width:100%; padding:10px; background:#000; color:#fff; border:none; cursor:pointer;">ĐĂNG NHẬP</button>
    </form>
</body>
</html>
