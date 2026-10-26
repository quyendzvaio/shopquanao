
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

