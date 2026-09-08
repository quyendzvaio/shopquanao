-- Fix mislabeled catalog color variants (SRS FR-003).
-- 52 "Áo Khoác Bomber Kaki Đen" and 63 "Áo Sơ Mi Caro Đỏ Đen" carry black in
-- the product name but were backfilled with color_id NULL, so the SQL color
-- filter excluded them from every black search.
-- 61 "Áo Dài Tay Cổ Tim" was mislabeled purple: "Cổ Tim" is a neckline, not a
-- color. Reset to unknown for manual review instead of inventing a color.
UPDATE product_variants
SET color_id = 1,
    variant_key = CONCAT('legacy|color:black|size:', `size`),
    updated_at = CURRENT_TIMESTAMP
WHERE product_id IN (52, 63) AND color_id IS NULL;

UPDATE product_variants
SET color_id = NULL,
    variant_key = CONCAT('legacy|color:unknown|size:', `size`),
    updated_at = CURRENT_TIMESTAMP
WHERE product_id = 61 AND color_id = 12;
