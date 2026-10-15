<?php
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'shop_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$retries = (int) (getenv('DB_CONNECT_RETRIES') ?: 1);
$attempt = 0;
$pdo = null;

do {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        break;
    } catch (PDOException $e) {
        $attempt++;
        if ($attempt >= $retries) {
            die("Lỗi kết nối database: " . $e->getMessage());
        }
        usleep(250000);
    }
} while (true);

if (!$pdo instanceof PDO) {
    die("Lỗi kết nối database: không thể khởi tạo PDO");
}
