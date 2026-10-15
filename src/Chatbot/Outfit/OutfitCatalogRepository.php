<?php

/** Reads the private catalog in the shape OutfitBuilder needs. */
final class OutfitCatalogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<OutfitItem> every product that can occupy an outfit slot */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT id, category_id, name, price, stock, image, style_tags FROM products ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        $sizes = $this->sizesByProduct();
        $items = [];
        foreach ($rows as $row) {
            $name = (string)$row['name'];
            $slot = OutfitSlot::resolve((int)$row['category_id'], $name);
            if ($slot === null) continue;
            $id = (int)$row['id'];
            $items[] = new OutfitItem(
                $id,
                $name,
                (int)round((float)$row['price']),
                (int)$row['stock'],
                (int)$row['category_id'],
                $slot,
                StyleTag::parseList((string)($row['style_tags'] ?? '')),
                $sizes[$id] ?? [],
                OutfitPalette::isStatement($name),
                (string)($row['image'] ?? '')
            );
        }
        return $items;
    }

    /** @return array<int,list<string>> legacy product_sizes plus active in-stock variant sizes */
    private function sizesByProduct(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT product_id, size_name FROM product_sizes')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['product_id']][] = strtoupper(trim((string)$row['size_name']));
        }
        try {
            $variants = $this->pdo->query(
                'SELECT product_id, size FROM product_variants
                 WHERE is_active = 1 AND size IS NOT NULL AND (stock IS NULL OR stock > 0)'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $variants = []; // product_variants is optional (catalog backfill not run)
        }
        foreach ($variants as $row) {
            $map[(int)$row['product_id']][] = strtoupper(trim((string)$row['size']));
        }
        foreach ($map as $id => $sizes) {
            $sizes = array_values(array_unique(array_filter($sizes)));
            sort($sizes);
            $map[$id] = $sizes;
        }
        return $map;
    }
}
