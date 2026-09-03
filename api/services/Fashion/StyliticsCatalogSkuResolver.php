<?php

/** Default SKU resolver: variant SKU first, then the catalog-sync fallback identifier. */
final class StyliticsCatalogSkuResolver implements StyliticsAnchorSkuResolverContract
{
    public function __construct(private PDO $pdo) {}

    public function resolveSku(int $shopProductId, ?int $shopVariantId = null): string
    {
        $sql = 'SELECT sku FROM product_variants WHERE product_id = ? AND is_active = 1';
        $args = [$shopProductId];
        if ($shopVariantId !== null) {
            $sql .= ' AND id = ?';
            $args[] = $shopVariantId;
        }
        $sql .= ' AND sku IS NOT NULL AND sku <> "" ORDER BY id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $sku = $stmt->fetchColumn();
        if (is_string($sku) && trim($sku) !== '') return trim($sku);

        // No variant SKU on file: fall back to the catalog-sync stable identifier.
        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE id = ?');
        $stmt->execute([$shopProductId]);
        if ($stmt->fetchColumn() === false) {
            throw new StyliticsApiException('ANCHOR_NOT_FOUND', 'Private anchor product was not found');
        }
        return 'shop-' . $shopProductId . ($shopVariantId !== null ? '-v' . $shopVariantId : '');
    }
}
