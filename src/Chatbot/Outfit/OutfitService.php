<?php

/** Tool `build_outfit`: validated arguments in, private-catalog sets out. */
final class OutfitService
{
    private const SIZED_CATEGORIES = [1, 2, 3];
    private const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function build(array $arguments): array
    {
        $tags = array_values(array_filter((array)($arguments['style_tags'] ?? []), [StyleTag::class, 'isValid']));
        $budget = (int)($arguments['budget'] ?? 0) > 0 ? (int)$arguments['budget'] : null;
        $anchor = (int)($arguments['product_id'] ?? 0) > 0 ? (int)$arguments['product_id'] : null;
        $sizeByCategory = $this->sizeByCategory($arguments);

        $result = (new OutfitBuilder())->build(
            (new OutfitCatalogRepository($this->pdo))->all(),
            new OutfitRequest(array_slice($tags, 0, StyleTag::MAX_PER_REQUEST), $budget, $sizeByCategory, $anchor)
        );

        $outfits = [];
        $products = [];
        foreach ($result->sets as $index => $set) {
            $outfits[] = [
                'outfit_index' => $index + 1,
                'product_ids' => $set->ids(),
                'total_price' => $set->total,
                'matched_tags' => $set->matchedTags,
            ];
            foreach ($set->items as $item) {
                $products[] = $item->toProduct() + ['outfit_index' => $index + 1];
            }
        }

        return [
            'status' => $outfits === [] ? 'no_outfit' : 'ok',
            'reason' => $result->reason,
            'style_tags' => $result->tags,
            'budget' => $budget,
            'cheapest_total' => $result->cheapestTotal,
            'size_by_category' => $sizeByCategory,
            'outfits' => $outfits,
            'products' => $products,
        ];
    }

    /** @return array<int,string> */
    private function sizeByCategory(array $arguments): array
    {
        $size = strtoupper(trim((string)($arguments['size'] ?? '')));
        if (in_array($size, self::SIZES, true)) {
            return array_fill_keys(self::SIZED_CATEGORIES, $size);
        }
        $height = (int)($arguments['height'] ?? 0);
        $weight = (int)($arguments['weight'] ?? 0);
        if ($height <= 0 || $weight <= 0) {
            return [];
        }
        $sizes = new SizeService($this->pdo);
        $map = [];
        foreach (self::SIZED_CATEGORIES as $categoryId) {
            $recommended = $sizes->suggest(['height' => $height, 'weight' => $weight, 'category_id' => $categoryId])['recommended'] ?? null;
            if (is_array($recommended) && ($recommended['size_name'] ?? '') !== '') {
                $map[$categoryId] = strtoupper((string)$recommended['size_name']);
            }
        }
        return $map;
    }
}
