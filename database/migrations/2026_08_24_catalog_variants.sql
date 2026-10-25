ALTER TABLE categories
    ADD COLUMN canonical_key varchar(64) DEFAULT NULL AFTER name,
    ADD COLUMN family varchar(32) DEFAULT NULL AFTER canonical_key
;

UPDATE categories SET canonical_key = 'tops', family = 'apparel' WHERE id = 1;
UPDATE categories SET canonical_key = 'bottoms', family = 'apparel' WHERE id = 2;
UPDATE categories SET canonical_key = 'dresses_skirts', family = 'apparel' WHERE id = 3;
UPDATE categories SET canonical_key = 'accessories', family = 'accessory' WHERE id = 4;

INSERT INTO categories (id, name, canonical_key, family)
VALUES (5, 'Giày dép', 'footwear', 'footwear')
ON DUPLICATE KEY UPDATE
    name = VALUES(name), canonical_key = VALUES(canonical_key), family = VALUES(family)
;

ALTER TABLE categories
    MODIFY canonical_key varchar(64) NOT NULL,
    MODIFY family varchar(32) NOT NULL,
    ADD UNIQUE KEY uq_categories_canonical_key (canonical_key),
    ADD INDEX idx_categories_family (family)
;

CREATE TABLE IF NOT EXISTS product_subcategories (
    id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_id int NOT NULL,
    canonical_key varchar(64) NOT NULL,
    display_name varchar(100) NOT NULL,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_subcategory_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT uq_product_subcategory_key UNIQUE (category_id, canonical_key),
    CONSTRAINT uq_product_subcategory_id_category UNIQUE (id, category_id),
    INDEX idx_product_subcategory_lookup (canonical_key, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO product_subcategories (category_id, canonical_key, display_name) VALUES
    (5, 'sneakers', 'Giày sneaker'),
    (5, 'dress_shoes', 'Giày tây'),
    (5, 'loafers', 'Giày loafer'),
    (5, 'boots', 'Bốt'),
    (5, 'sandals', 'Dép sandal'),
    (5, 'other', 'Giày dép khác')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

ALTER TABLE products
    ADD COLUMN subcategory_id int DEFAULT NULL AFTER category_id,
    ADD INDEX idx_products_category_subcategory (category_id, subcategory_id),
    ADD CONSTRAINT fk_product_subcategory
        FOREIGN KEY (subcategory_id, category_id)
        REFERENCES product_subcategories(id, category_id) ON DELETE RESTRICT
;

CREATE TABLE IF NOT EXISTS colors (
    id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canonical_key varchar(32) NOT NULL,
    display_name varchar(64) NOT NULL,
    external_code varchar(100) DEFAULT NULL,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_colors_canonical_key UNIQUE (canonical_key),
    INDEX idx_colors_display_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO colors (canonical_key, display_name) VALUES
    ('black', 'Đen'),
    ('white', 'Trắng'),
    ('gray', 'Xám'),
    ('navy', 'Xanh navy'),
    ('blue', 'Xanh dương'),
    ('brown', 'Nâu'),
    ('beige', 'Be'),
    ('khaki', 'Kaki'),
    ('green', 'Xanh lá'),
    ('red', 'Đỏ'),
    ('pink', 'Hồng'),
    ('purple', 'Tím'),
    ('yellow', 'Vàng'),
    ('orange', 'Cam'),
    ('cream', 'Kem'),
    ('multi', 'Đa màu'),
    ('other', 'Khác')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

CREATE TABLE IF NOT EXISTS product_variants (
    id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id int NOT NULL,
    variant_key varchar(191) NOT NULL,
    sku varchar(191) DEFAULT NULL,
    color_id int DEFAULT NULL,
    size varchar(20) DEFAULT NULL,
    price decimal(10,2) DEFAULT NULL,
    stock int DEFAULT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_variant_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_variant_color
        FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE SET NULL,
    CONSTRAINT uq_product_variant_key UNIQUE (product_id, variant_key),
    CONSTRAINT uq_product_variant_sku UNIQUE (sku),
    INDEX idx_product_variant_lookup (product_id, is_active, color_id, size),
    INDEX idx_product_variant_availability (is_active, stock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
