<?php
/**
 * Test bootstrap — loads environment + helpers.
 */

// Define test constants
define('TEST_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

// Autoload helpers
require_once ROOT_DIR . '/api/cache/Cache.php';
require_once ROOT_DIR . '/api/controllers/chatbot/llm/LLMProvider.php';
require_once ROOT_DIR . '/api/controllers/chatbot/llm/LLMResponse.php';
require_once ROOT_DIR . '/api/controllers/chatbot/ProductAttributeNormalizer.php';
require_once ROOT_DIR . '/api/controllers/chatbot/ChatbotMemory.php';
require_once ROOT_DIR . '/api/controllers/chatbot/KnowledgeRetriever.php';
require_once ROOT_DIR . '/api/controllers/chatbot/ToolRegistry.php';
require_once ROOT_DIR . '/api/controllers/chatbot/llm/LLMFactory.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/PartialParseResult.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/CapabilityRegistry.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/DeterministicIntentParser.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ConflictDetector.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ConflictResolver.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/SemanticEntityEnricher.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/MergeEngine.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/PlanValidator.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/IntentResolver.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ToolPlanner.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ParallelToolExecutor.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/EvidenceNormalizer.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ProductConstraintVerifier.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ObservationEvaluator.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/LightweightEvidenceScorer.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/EvidenceDecisionRouter.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/NoProgressDetector.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/EvidenceExecutionLoop.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ResponseGenerator.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/OnlineValidator.php';
require_once ROOT_DIR . '/api/controllers/chatbot/ChatbotService.php';

/**
 * Load a config-like PDO for testing (no global $pdo dependency).
 * Uses env vars or defaults.
 */
function getTestPDO(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'shop_test';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        echo "⚠️  DB not available ({$e->getMessage()}), using SQLite fallback.\n";
        $pdo = new PDO('sqlite:' . sys_get_temp_dir() . '/shop_test.db', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        initSQLiteSchema($pdo);
    }

    return $pdo;
}

function initSQLiteSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        name TEXT NOT NULL,
        price REAL NOT NULL,
        stock INTEGER DEFAULT 0,
        description TEXT,
        image TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS faqs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        question TEXT NOT NULL,
        answer TEXT NOT NULL,
        category TEXT DEFAULT 'general',
        priority INTEGER DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS size_guides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        category_id INTEGER,
        size_name TEXT NOT NULL,
        height_from INTEGER,
        height_to INTEGER,
        weight_from INTEGER,
        weight_to INTEGER
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_sizes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        size_name TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        session_token TEXT UNIQUE,
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        message TEXT NOT NULL,
        metadata TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tool_executions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        tool_name TEXT NOT NULL,
        arguments TEXT,
        result TEXT,
        duration_ms INTEGER,
        success INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        api_token TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        role TEXT DEFAULT 'user',
        status INTEGER DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        total_price REAL,
        status TEXT DEFAULT 'Pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_session_memory (
        session_id INTEGER PRIMARY KEY,
        summary TEXT,
        slots TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_long_term_memory (
        user_id INTEGER PRIMARY KEY,
        preferences TEXT,
        stable_facts TEXT,
        important_events TEXT,
        feedback TEXT,
        purchase_history TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    seedTestData($pdo);
}

function seedTestData(PDO $pdo): void {
    // Categories
    $pdo->exec("INSERT OR IGNORE INTO categories (id, name) VALUES (1, 'Áo'), (2, 'Quần'), (3, 'Váy & Đầm'), (4, 'Phụ kiện')");
    // Products
    $pdo->exec("INSERT OR IGNORE INTO products (id, category_id, name, price, stock, description) VALUES
        (50, 1, 'Áo Thun Cotton Basic Trắng', 180000, 10, 'Chất liệu cotton 100%'),
        (51, 1, 'Áo Sơ Mi Linen Xanh', 320000, 5, 'Vải linen tự nhiên'),
        (52, 1, 'Áo Khoác Bomber Kaki Đen', 550000, 12, 'Thiết kế mạnh mẽ'),
        (53, 1, 'Áo Len Cổ Tròn Xám', 415000, 8, 'Sợi len dệt cao cấp'),
        (54, 1, 'Áo Polo Thể Thao Cao Cấp Đỏ', 290000, 3, 'Form dáng slim-fit'),
        (58, 1, 'Áo Thun Graphic Phối Màu', 210000, 15, 'Họa tiết in nhiệt'),
        (64, 1, 'Áo Len Cao Cổ Màu Đất', 485000, 9, 'Cổ cao giữ ấm'),
        (65, 2, 'Quần Jeans Slimfit Xanh Đậm', 690000, 5, 'Dáng ôm vừa vặn'),
        (66, 2, 'Quần Tây Ống Đứng Xám', 540000, 11, 'Chất vải tuyết mưa'),
        (75, 3, 'Váy Maxi Voan Tay Phồng Hồng', 850000, 6, 'Vải voan tơ mềm mại')
    ");
    foreach ([50, 51, 52, 53, 54, 58, 64, 65, 66, 75] as $productId) {
        foreach (['S', 'M', 'L', 'XL'] as $size) {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO product_sizes (product_id, size_name) VALUES (?, ?)");
            $stmt->execute([$productId, $size]);
        }
    }
    // FAQs
    $pdo->exec("INSERT OR IGNORE INTO faqs (id, question, answer, category, priority) VALUES
        (1, 'Thời gian giao hàng?', '2-5 ngày', 'shipping', 1),
        (2, 'Có đổi trả được không?', 'Đổi trả 7 ngày', 'return', 1)
    ");
}
