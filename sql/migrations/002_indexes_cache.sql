-- ============================================================
-- Migration: Add indexes for query performance + cache table
-- ============================================================

-- 1. FULLTEXT index on products.name for fast text search
--    (supports MATCH...AGAINST which is 10-100x faster than LIKE %...%)
ALTER TABLE products ADD FULLTEXT INDEX ft_products_name (name);

-- 2. Composite index on (category_id, price) for filtered product queries
--    Covers: WHERE category_id=? AND price BETWEEN ? AND ? ORDER BY price
ALTER TABLE products ADD INDEX idx_category_price (category_id, price);

-- 3. Index on price alone for price-only queries
ALTER TABLE products ADD INDEX idx_price (price);

-- 4. Composite index on (category_id, price, stock) for common filter combo
ALTER TABLE products ADD INDEX idx_category_price_stock (category_id, price, stock);

-- 5. Composite index for chat_messages history loading (ORDER BY id ASC)
ALTER TABLE chat_messages ADD INDEX idx_session_id_created (session_id, id);

-- 6. Composite index for chat_sessions user lookup (ORDER BY updated_at DESC)
ALTER TABLE chat_sessions ADD INDEX idx_user_updated (user_id, updated_at);

-- 7. Index for faq queries
ALTER TABLE faqs ADD INDEX idx_category_priority (category, priority);

-- 8. Index for tool_executions monitoring
ALTER TABLE tool_executions ADD INDEX idx_tool_created (tool_name, created_at);

-- 9. Cache invalidation tracker table (for admin to invalidate specific caches)
CREATE TABLE IF NOT EXISTS cache_tags (
    id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tag varchar(100) NOT NULL,
    invalidated_at timestamp NULL DEFAULT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    UNIQUE KEY tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed cache tags
INSERT IGNORE INTO cache_tags (tag) VALUES
    ('products'),
    ('faqs'),
    ('categories'),
    ('size_guides'),
    ('outfits');
