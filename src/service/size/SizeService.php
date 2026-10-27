<?php


final class SizeService
{
    private SizeRepository $sizeRows;

    public function __construct(private PDO $pdo)
    {
        $this->sizeRows = new SizeRepository($pdo);
    }

    /** Size guide rows for GET /api/size-guide: one category when $categoryId is truthy, else all. */
    public function guides(?int $categoryId): array {
        return $categoryId ? $this->sizeRows->guidesForCategory($categoryId) : $this->sizeRows->allGuides();
    }

    /** Legacy product_sizes rows for GET /api/products/{id}/sizes, in PDO's default (FETCH_BOTH) mode as always. */
    public function productSizes(int $productId): array {
        return $this->sizeRows->sizesForProduct($productId, PDO::FETCH_BOTH);
    }

    public function suggest(array $args): array {
        $queryParams = [
            'height' => $args['height'] ?? 0,
            'weight' => $args['weight'] ?? 0,
            'category_id' => $args['category_id'] ?? '',
        ];

        // Check cache
        $cached = Cache::getSizeGuide($queryParams);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->suggestSizeDirect($queryParams);

        if (!isset($result['error'])) {
            Cache::setSizeGuide($queryParams, $result);
        }

        return $result;
    }

    private function suggestSizeDirect(array $queryParams): array {
        $height = (int)($queryParams['height'] ?? 0);
        $weight = (int)($queryParams['weight'] ?? 0);
        $catId = (int)($queryParams['category_id'] ?? 0);
        if ($height <= 0 || $weight <= 0) return ['error' => 'Height and weight are required'];

        $rows = $catId > 0
            ? $this->sizeRows->suggestionGuidesForCategory($catId)
            : $this->sizeRows->allSuggestionGuides();
        $sizes = $this->dedupeSizeRows($rows);
        $recommended = null;
        foreach ($sizes as $s) {
            $hOk = (!$s['height_from'] || $height >= (int)$s['height_from'])
                && (!$s['height_to'] || $height <= (int)$s['height_to']);
            $wOk = (!$s['weight_from'] || $weight >= (int)$s['weight_from'])
                && (!$s['weight_to'] || $weight <= (int)$s['weight_to']);
            if ($hOk && $wOk) {
                $recommended = $s;
                break;
            }
        }
        return ['recommended' => $recommended, 'sizes' => $sizes];
    }

    private function dedupeSizeRows(array $rows): array {
        $seen = [];
        $sizes = [];
        foreach ($rows as $s) {
            if (!is_array($s)) continue;
            $key = implode('|', [
                $s['category_id'] ?? '',
                strtoupper((string)($s['size_name'] ?? '')),
                $s['height_from'] ?? '',
                $s['height_to'] ?? '',
                $s['weight_from'] ?? '',
                $s['weight_to'] ?? '',
            ]);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            foreach (['id', 'category_id', 'product_id', 'height_from', 'height_to', 'weight_from', 'weight_to'] as $field) {
                if (array_key_exists($field, $s) && $s[$field] !== null && $s[$field] !== '') {
                    $s[$field] = (int)$s[$field];
                }
            }
            $sizes[] = $s;
        }
        return $sizes;
    }
}
