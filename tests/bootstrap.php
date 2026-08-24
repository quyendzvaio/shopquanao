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
require_once ROOT_DIR . '/api/services/Catalog/CatalogTaxonomy.php';
require_once ROOT_DIR . '/api/services/Catalog/CatalogColor.php';
require_once ROOT_DIR . '/api/services/Catalog/ProductVariant.php';
require_once ROOT_DIR . '/api/services/Catalog/CatalogVariantHydrator.php';
require_once ROOT_DIR . '/api/services/Catalog/CatalogVariantBackfill.php';
require_once ROOT_DIR . '/api/controllers/chatbot/ChatbotMemory.php';
require_once ROOT_DIR . '/api/controllers/chatbot/KnowledgeRetriever.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionProvider.php';
require_once ROOT_DIR . '/api/services/CartService.php';
require_once ROOT_DIR . '/api/services/OrderService.php';
require_once ROOT_DIR . '/api/services/Fashion/AnchorProductRef.php';
require_once ROOT_DIR . '/api/services/Fashion/ComplementaryItemRequirement.php';
require_once ROOT_DIR . '/api/services/Fashion/ComplementaryPlan.php';
require_once ROOT_DIR . '/api/services/Fashion/FindMineProviderException.php';
require_once ROOT_DIR . '/api/services/Fashion/FindMineV3ResponseAdapter.php';
require_once ROOT_DIR . '/api/services/Fashion/FindMineConfig.php';
require_once ROOT_DIR . '/api/services/Fashion/FindMineMcpClientContract.php';
require_once ROOT_DIR . '/api/services/Fashion/FindMineMcpClient.php';
require_once ROOT_DIR . '/api/services/Fashion/RawFashionSuggestion.php';
require_once ROOT_DIR . '/api/services/Fashion/RawFashionSuggestionProvider.php';
require_once ROOT_DIR . '/api/services/Fashion/FindMineDemoFashionProvider.php';
require_once ROOT_DIR . '/api/services/Fashion/ExtractedFashionItem.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionAttributeExtractor.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionExtractionCache.php';
require_once ROOT_DIR . '/api/services/Fashion/ApplicationFashionExtractionCache.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionPipelineMetrics.php';
require_once ROOT_DIR . '/api/services/Fashion/StructuredLogFashionMetrics.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionExtractionException.php';
require_once ROOT_DIR . '/api/services/Fashion/DeterministicFashionAttributeParser.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionExtractionSemanticValidator.php';
require_once ROOT_DIR . '/api/services/Fashion/LlmFashionAttributeExtractor.php';
require_once ROOT_DIR . '/api/services/Fashion/FindMineFashionProvider.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionProviderResult.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionProviderProductMapping.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionProviderMappingRepository.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionRequirement.php';
require_once ROOT_DIR . '/api/services/Fashion/ShopComplementaryRequirement.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionTaxonomyNormalizer.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionRequirementNormalizer.php';
require_once ROOT_DIR . '/api/services/Fashion/ConcurrentProductSearchGateway.php';
require_once ROOT_DIR . '/api/services/Fashion/InternalShopConcurrentProductSearchGateway.php';
require_once ROOT_DIR . '/api/services/Fashion/ParallelComplementaryProductSearcher.php';
require_once ROOT_DIR . '/api/services/Fashion/ComplementaryProductFinder.php';
require_once ROOT_DIR . '/api/services/Fashion/ProactiveStylingStateMachine.php';
require_once ROOT_DIR . '/api/services/Fashion/ProactiveStylingStateStore.php';
require_once ROOT_DIR . '/api/services/Fashion/CartItemAddedOutbox.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionEventBus.php';
require_once ROOT_DIR . '/api/services/Fashion/RedisFashionEventBus.php';
require_once ROOT_DIR . '/api/services/Fashion/FashionOutboxPublisher.php';
require_once ROOT_DIR . '/api/services/Fashion/CartItemAddedConsumer.php';
require_once ROOT_DIR . '/api/services/Fashion/ProactiveChatTurnService.php';
require_once ROOT_DIR . '/api/services/Fashion/ProactiveCartStylingService.php';
require_once ROOT_DIR . '/api/controllers/chatbot/ToolRegistry.php';
require_once ROOT_DIR . '/api/services/Fashion/OfflineFashionMappingImporter.php';
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
        subcategory_id INTEGER,
        name TEXT NOT NULL,
        price REAL NOT NULL,
        stock INTEGER DEFAULT 0,
        description TEXT,
        image TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        canonical_key TEXT NOT NULL UNIQUE,
        family TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_subcategories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        canonical_key TEXT NOT NULL,
        display_name TEXT NOT NULL,
        UNIQUE(category_id, canonical_key)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS colors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        canonical_key TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        external_code TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_variants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        variant_key TEXT NOT NULL,
        sku TEXT UNIQUE,
        color_id INTEGER,
        size TEXT,
        price REAL,
        stock INTEGER,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(product_id, variant_key)
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS cart (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER DEFAULT 1,
        size TEXT DEFAULT 'S',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        product_id INTEGER,
        quantity INTEGER,
        price REAL
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS fashion_provider_product_mapping (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shop_product_id INTEGER NOT NULL,
        shop_variant_id INTEGER,
        mapping_scope TEXT NOT NULL DEFAULT 'product',
        provider TEXT NOT NULL,
        provider_product_id TEXT NOT NULL,
        provider_variant_id TEXT NOT NULL DEFAULT '',
        provider_color_id TEXT NOT NULL DEFAULT '',
        provider_identifiers TEXT,
        sync_status TEXT NOT NULL DEFAULT 'pending',
        sync_version TEXT,
        last_synced_at DATETIME,
        last_error TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(provider, shop_product_id, mapping_scope),
        UNIQUE(provider, provider_product_id, provider_variant_id, provider_color_id)
    )");
    seedTestData($pdo);
}

function seedTestData(PDO $pdo): void {
    // Categories
    $pdo->exec("INSERT OR IGNORE INTO categories (id, name, canonical_key, family) VALUES
        (1, 'Áo', 'tops', 'apparel'),
        (2, 'Quần', 'bottoms', 'apparel'),
        (3, 'Váy & Đầm', 'dresses_skirts', 'apparel'),
        (4, 'Phụ kiện', 'accessories', 'accessory'),
        (5, 'Giày dép', 'footwear', 'footwear')");
    $pdo->exec("INSERT OR IGNORE INTO product_subcategories (id, category_id, canonical_key, display_name) VALUES
        (501, 5, 'sneakers', 'Giày sneaker'),
        (502, 5, 'dress_shoes', 'Giày tây'),
        (503, 5, 'loafers', 'Giày loafer'),
        (504, 5, 'boots', 'Bốt'),
        (505, 5, 'sandals', 'Dép sandal'),
        (506, 5, 'other', 'Giày dép khác')");
    $pdo->exec("INSERT OR IGNORE INTO colors (id, canonical_key, display_name) VALUES
        (1, 'black', 'Đen'), (2, 'white', 'Trắng'), (3, 'gray', 'Xám'),
        (4, 'navy', 'Xanh navy'), (5, 'blue', 'Xanh dương'), (6, 'brown', 'Nâu'),
        (7, 'beige', 'Be'), (8, 'khaki', 'Kaki'), (9, 'green', 'Xanh lá'),
        (10, 'red', 'Đỏ'), (11, 'pink', 'Hồng'), (12, 'purple', 'Tím'),
        (13, 'yellow', 'Vàng'), (14, 'orange', 'Cam'), (15, 'cream', 'Kem'),
        (16, 'multi', 'Đa màu'), (17, 'other', 'Khác')");
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
