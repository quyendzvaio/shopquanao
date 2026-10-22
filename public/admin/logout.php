<?php
// 1. Khởi động session để có thể hủy nó
session_start();

// 2. Xóa bỏ tất cả các biến session
session_unset();

// 3. Hủy hoàn toàn phiên làm việc (session)
session_destroy();

// 4. CHỈNH SỬA TẠI ĐÂY:
// Dùng "../index.php" để nhảy ra khỏi thư mục admin và quay về trang chủ khách hàng
header("Location: ../index.php");
exit();
?>