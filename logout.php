<?php
// 1. Bắt đầu session để có quyền truy cập vào các dữ liệu đang lưu
session_start();

// 2. Xóa sạch tất cả các biến trong session (giỏ hàng, user_id, username...)
$_SESSION = array();

// 3. Nếu muốn xóa sạch dấu vết của cookie session trên trình duyệt
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Hủy bỏ session trên server
session_destroy();

// 5. Chuyển hướng người dùng về trang chủ ngay lập tức
header("Location: index.php");
exit(); // Ngắt mọi xử lý phía sau để đảm bảo an toàn
?>