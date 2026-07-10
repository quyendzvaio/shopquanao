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

// Auto-create tables if missing
try {
    $existing = $pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = " . $pdo->quote($db))->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('users', $existing)) {
        $exec = function($sql) use ($pdo) { try { $pdo->exec($sql); } catch (Exception $e) {} };
        $exec("CREATE TABLE IF NOT EXISTS users (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, username varchar(50) NOT NULL, email varchar(100) NOT NULL, password varchar(255) NOT NULL, api_token varchar(64) DEFAULT NULL, created_at timestamp NOT NULL DEFAULT current_timestamp(), role varchar(20) DEFAULT 'user', status tinyint DEFAULT 1, UNIQUE KEY email (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS categories (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS products (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, category_id int DEFAULT NULL, name varchar(255) NOT NULL, price decimal(10,2) NOT NULL, stock int NOT NULL DEFAULT 0, description text DEFAULT NULL, image varchar(255) DEFAULT NULL, KEY category_id (category_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS product_sizes (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id int DEFAULT NULL, size_name varchar(10) DEFAULT NULL, KEY product_id (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS cart (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id int NOT NULL, product_id int NOT NULL, quantity int DEFAULT 1, size varchar(10) DEFAULT 'S', created_at timestamp NOT NULL DEFAULT current_timestamp(), KEY user_id (user_id), KEY product_id (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS orders (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id int DEFAULT NULL, total_price decimal(10,2) DEFAULT NULL, status varchar(50) DEFAULT 'Pending', created_at timestamp NOT NULL DEFAULT current_timestamp(), KEY user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS order_items (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id int DEFAULT NULL, product_id int DEFAULT NULL, quantity int DEFAULT NULL, price decimal(10,2) DEFAULT NULL, KEY order_id (order_id), KEY product_id (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS reviews (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id int DEFAULT NULL, user_id int DEFAULT NULL, rating int DEFAULT NULL, comment text DEFAULT NULL, created_at timestamp NOT NULL DEFAULT current_timestamp(), KEY product_id (product_id), KEY user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS faqs (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, question text NOT NULL, answer text NOT NULL, category varchar(50) DEFAULT 'general', priority int DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS size_guides (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id int DEFAULT NULL, category_id int DEFAULT NULL, size_name varchar(10) NOT NULL, height_from int DEFAULT NULL, height_to int DEFAULT NULL, weight_from int DEFAULT NULL, weight_to int DEFAULT NULL, description varchar(255) DEFAULT NULL, KEY product_id (product_id), KEY category_id (category_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS outfit_suggestions (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id int NOT NULL, paired_product_id int NOT NULL, note varchar(255) DEFAULT NULL, KEY product_id (product_id), KEY paired_product_id (paired_product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS chat_sessions (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id int DEFAULT NULL, session_token varchar(64) NOT NULL UNIQUE, status varchar(20) DEFAULT 'active', created_at timestamp NOT NULL DEFAULT current_timestamp(), updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), KEY user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS chat_messages (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id int NOT NULL, role varchar(20) NOT NULL DEFAULT 'user', message text NOT NULL, metadata longtext DEFAULT NULL, created_at timestamp NOT NULL DEFAULT current_timestamp(), KEY session_id (session_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exec("CREATE TABLE IF NOT EXISTS tool_executions (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id int NOT NULL, tool_name varchar(100) NOT NULL, arguments longtext DEFAULT NULL, result longtext DEFAULT NULL, duration_ms int DEFAULT NULL, success tinyint DEFAULT 1, created_at timestamp NOT NULL DEFAULT current_timestamp(), KEY session_id (session_id), KEY tool_name (tool_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

// Run pending migrations
$migrationsDir = __DIR__ . '/../sql/migrations';
if (is_dir($migrationsDir)) {
    require_once $migrationsDir . '/001_init.php';
    runMigrations($pdo, $migrationsDir);
}
?>
