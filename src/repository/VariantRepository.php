<?php

/** Reads and writes product_variants and reads colors. */
final class VariantRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<int> $productIds non-empty
     * @return list<array<string,mixed>> active variants with their color, by product then id
     */
    public function activeByProductIds(array $productIds): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT v.id, v.product_id, v.variant_key, v.sku, v.color_id, v.size, v.price, v.stock,
                        v.is_active, c.canonical_key, c.display_name, c.external_code
                 FROM product_variants v
                 LEFT JOIN colors c ON c.id = v.color_id
                 WHERE v.product_id IN ($placeholders) AND v.is_active = 1
                 ORDER BY v.product_id, v.id"
        );
        $stmt->execute($productIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{id:mixed,canonical_key:mixed}> */
    public function colorKeys(): array
    {
        return $this->pdo->query('SELECT id, canonical_key FROM colors')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function exists(int $productId, string $variantKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM product_variants WHERE product_id = ? AND variant_key = ?');
        $stmt->execute([$productId, $variantKey]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return list<mixed> variant_key of every variant of the product */
    public function keysForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT variant_key FROM product_variants WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<array{id:mixed,variant_key:mixed,size:mixed}> the product's backfilled 'legacy|' variants */
    public function legacyVariants(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, variant_key, size FROM product_variants WHERE product_id = ? AND variant_key LIKE ?'
        );
        $stmt->execute([$productId, 'legacy|%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function rekey(int $variantId, string $variantKey, int $colorId): void
    {
        $update = $this->pdo->prepare(
            'UPDATE product_variants SET variant_key = ?, color_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $update->execute([$variantKey, $colorId, $variantId]);
    }

    public function insertLegacy(int $productId, string $variantKey, ?int $colorId, ?string $size): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants
                        (product_id, variant_key, sku, color_id, size, price, stock, is_active)
                     VALUES (?, ?, NULL, ?, ?, NULL, NULL, 1)'
        );
        $stmt->execute([$productId, $variantKey, $colorId, $size]);
    }
}
