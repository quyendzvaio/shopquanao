<?php


/** Batch-loads all structured variant/color data behind one catalog interface. */
final class CatalogVariantHydrator
{
    private VariantRepository $variantRows;

    public function __construct(private PDO $pdo)
    {
        $this->variantRows = new VariantRepository($pdo);
    }

    /** @return list<array<string, mixed>> */
    public function enrich(array $products): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn($product) => (int)($product['id'] ?? 0),
            $products
        ))));
        $rowsByProduct = $this->loadVariantRows($ids);

        foreach ($products as &$product) {
            $productId = (int)($product['id'] ?? 0);
            $basePrice = (float)($product['price'] ?? 0);
            $baseStock = (int)($product['stock'] ?? 0);
            $variants = [];
            $colors = [];
            $canonicalColors = [];
            $variantSizes = [];

            foreach ($rowsByProduct[$productId] ?? [] as $row) {
                $color = null;
                if ((int)($row['color_id'] ?? 0) > 0) {
                    $color = new CatalogColor(
                        (int)$row['color_id'],
                        (string)$row['canonical_key'],
                        (string)$row['display_name'],
                        $this->nullable($row['external_code'] ?? null)
                    );
                    $colors[$color->canonical] = $color->toArray();
                    $canonicalColors[] = $color->canonical;
                }
                $variant = new ProductVariant(
                    (int)$row['id'],
                    (int)$row['product_id'],
                    (string)$row['variant_key'],
                    $this->nullable($row['sku'] ?? null),
                    $color,
                    $this->nullable($row['size'] ?? null),
                    isset($row['price']) ? (float)$row['price'] : null,
                    isset($row['stock']) ? (int)$row['stock'] : null,
                    (bool)$row['is_active']
                );
                $variants[] = $variant->toArray($basePrice, $baseStock);
                if ($variant->size !== null) $variantSizes[] = strtoupper($variant->size);
            }

            $product['variants'] = $variants;
            $product['colors'] = array_values($colors);
            $product['canonical_colors'] = array_values(array_unique($canonicalColors));
            $legacyColors = [];
            foreach ($product['canonical_colors'] as $canonical) {
                $legacy = ProductAttributeNormalizer::canonicalToLegacyColor($canonical);
                if ($legacy !== null) $legacyColors[] = $legacy;
            }
            $product['available_colors'] = array_values(array_unique(array_merge(
                $legacyColors,
                ProductAttributeNormalizer::extractColorsFromProduct($product)
            )));

            $legacySizes = ProductAttributeNormalizer::productSizes($product);
            $product['available_sizes'] = array_values(array_unique(array_merge($legacySizes, $variantSizes)));
        }
        unset($product);
        return $products;
    }

    private function loadVariantRows(array $productIds): array
    {
        if ($productIds === []) return [];
        try {
            $byProduct = [];
            foreach ($this->variantRows->activeByProductIds($productIds) as $row) {
                $byProduct[(int)$row['product_id']][] = $row;
            }
            return $byProduct;
        } catch (Throwable $error) {
            // Allows a rolling deploy where code reaches an instance before its
            // additive catalog migration. Legacy product fields still work.
            return [];
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
