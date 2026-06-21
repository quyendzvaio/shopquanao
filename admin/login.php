<?php
session_start();
if (isset($_POST['login'])) {
    $user = $_POST['username'];
    $pass = $_POST['password'];

    if ($user === 'admin' && $pass === '123456') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['role'] = 'admin';

        // nhận diện được tài khoản admin đang đăng nhập.
        // $_SESSION['user_id'] = 0;

        header("Location: index.php");
        exit();
    } else {
        $error = "Sai tài khoản hoặc mật khẩu!";
    }
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
        <?php if(isset($error)) echo "<p style='color:red; font-size:13px;'>$error</p>"; ?>
        <input type="text" name="username" placeholder="Username" required style="width:100%; padding:10px; margin:10px 0; border:1px solid #ddd;">
        <input type="password" name="password" placeholder="Password" required style="width:100%; padding:10px; margin:10px 0; border:1px solid #ddd;">
        <button type="submit" name="login" style="width:100%; padding:10px; background:#000; color:#fff; border:none; cursor:pointer;">ĐĂNG NHẬP</button>
    </form>
</body>
</html>
