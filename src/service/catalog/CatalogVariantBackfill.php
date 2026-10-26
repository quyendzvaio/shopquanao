<?php


/** Idempotently converts known legacy size/color facts into variant rows. */
final class CatalogVariantBackfill
{
    private ProductRepository $productRows;
    private SizeRepository $sizeRows;
    private VariantRepository $variantRows;

    public function __construct(private PDO $pdo)
    {
        $this->productRows = new ProductRepository($pdo);
        $this->sizeRows = new SizeRepository($pdo);
        $this->variantRows = new VariantRepository($pdo);
    }

    public function run(): array
    {
        $products = $this->productRows->allForBackfill();
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
                if ($this->variantRows->exists($productId, $variantKey)) continue;
                $this->variantRows->insertLegacy($productId, $variantKey, $colorId, $size);
                $report['variants_created']++;
            }
        }
        return $report;
    }

    private function loadSizes(): array
    {
        $sizes = [];
        foreach ($this->sizeRows->allProductSizes() as $row) {
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
        foreach ($this->variantRows->colorKeys() as $row) {
            $result[(string)$row['canonical_key']] = (int)$row['id'];
        }
        return $result;
    }

    private function variantState(int $productId): string
    {
        $keys = $this->variantRows->keysForProduct($productId);
        if ($keys === []) return 'none';
        foreach ($keys as $key) {
            if (!str_starts_with((string)$key, 'legacy|')) return 'custom';
        }
        return 'legacy';
    }

    private function reconcileLegacyVariantColor(int $productId, string $canonical, int $colorId): void
    {
        foreach ($this->variantRows->legacyVariants($productId) as $row) {
            $newKey = $this->variantKey($canonical, $this->nullableSize($row['size'] ?? null));
            $this->variantRows->rekey((int)$row['id'], $newKey, $colorId);
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
