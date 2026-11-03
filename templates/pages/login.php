
<div class="auth-form" style="max-width: 400px; margin: 50px auto; padding: 30px; border: 1px solid #ddd; background: #fff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
    <h2 style="text-align: center; margin-bottom: 25px; font-weight: bold; text-transform: uppercase;">Đăng nhập</h2>

    <?php if ($registerSuccess): ?>
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

