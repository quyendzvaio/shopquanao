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
        // TIMESTAMP columns are stored in UTC and read in the session zone: use PHP's offset (date.timezone in
        // docker/php.ini) so NOW(), the column defaults and every date shown agree with PHP's date().
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . date('P') . "'",
        ]);
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
