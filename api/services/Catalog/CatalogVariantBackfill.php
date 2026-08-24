<?php

require_once __DIR__ . '/../../controllers/chatbot/ProductAttributeNormalizer.php';

/** Idempotently converts known legacy size/color facts into variant rows. */
final class CatalogVariantBackfill
{
    public function __construct(private PDO $pdo)
    {
    }

    public function run(): array
    {
        $products = $this->pdo->query(
            'SELECT id, name, description FROM products ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $sizes = $this->loadSizes();
        $colors = $this->loadColors();
        $report = [
            'products_processed' => 0,
            'variants_created' => 0,
            'colors_normalized' => 0,
            'unknown_colors' => 0,
            'records_requiring_manual_review' => [],
        ];

        foreach ($products as $product) {
            $report['products_processed']++;
            $productId = (int)$product['id'];
            $variantState = $this->variantState($productId);
            if ($variantState === 'custom') {
                continue;
            }
            $detectedColors = ProductAttributeNormalizer::extractCanonicalColorsFromProduct($product);
            $canonical = count($detectedColors) === 1 ? $detectedColors[0] : null;
            $colorId = $canonical !== null ? ($colors[$canonical] ?? null) : null;
            if ($colorId !== null) {
                $report['colors_normalized']++;
                if ($variantState === 'legacy') {
                    $this->reconcileLegacyVariantColor($productId, $canonical, $colorId);
                }
            } else {
                $report['unknown_colors']++;
                $report['records_requiring_manual_review'][] = [
                    'product_id' => $productId,
                    'reason' => count($detectedColors) > 1 ? 'ambiguous_color' : 'unknown_color',
                    'detected_colors' => $detectedColors,
                ];
            }

            if ($variantState === 'legacy') continue;

            $productSizes = $sizes[$productId] ?? [null];
            foreach ($productSizes as $size) {
                $variantKey = $this->variantKey($canonical, $size);
                if ($this->variantExists($productId, $variantKey)) continue;
                $stmt = $this->pdo->prepare(
                    'INSERT INTO product_variants
                        (product_id, variant_key, sku, color_id, size, price, stock, is_active)
                     VALUES (?, ?, NULL, ?, ?, NULL, NULL, 1)'
                );
                $stmt->execute([$productId, $variantKey, $colorId, $size]);
                $report['variants_created']++;
            }
        }
        return $report;
    }

    private function loadSizes(): array
    {
        $sizes = [];
        foreach ($this->pdo->query(
            'SELECT product_id, size_name FROM product_sizes ORDER BY product_id, id'
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $size = ProductAttributeNormalizer::normalizeSize((string)$row['size_name']);
            if ($size !== null) $sizes[(int)$row['product_id']][] = $size;
        }
        foreach ($sizes as &$values) $values = array_values(array_unique($values));
        unset($values);
        return $sizes;
    }

    private function loadColors(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT id, canonical_key FROM colors')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string)$row['canonical_key']] = (int)$row['id'];
        }
        return $result;
    }

    private function variantExists(int $productId, string $variantKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM product_variants WHERE product_id = ? AND variant_key = ?');
        $stmt->execute([$productId, $variantKey]);
        return (bool)$stmt->fetchColumn();
    }

    private function variantState(int $productId): string
    {
        $stmt = $this->pdo->prepare('SELECT variant_key FROM product_variants WHERE product_id = ?');
        $stmt->execute([$productId]);
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($keys === []) return 'none';
        foreach ($keys as $key) {
            if (!str_starts_with((string)$key, 'legacy|')) return 'custom';
        }
        return 'legacy';
    }

    private function reconcileLegacyVariantColor(int $productId, string $canonical, int $colorId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, variant_key, size FROM product_variants WHERE product_id = ? AND variant_key LIKE ?'
        );
        $stmt->execute([$productId, 'legacy|%']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $newKey = $this->variantKey($canonical, $this->nullableSize($row['size'] ?? null));
            $update = $this->pdo->prepare(
                'UPDATE product_variants SET variant_key = ?, color_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $update->execute([$newKey, $colorId, (int)$row['id']]);
        }
    }

    private function nullableSize(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function variantKey(?string $color, ?string $size): string
    {
        return 'legacy|color:' . ($color ?? 'unknown') . '|size:' . ($size ?? 'none');
    }
}
