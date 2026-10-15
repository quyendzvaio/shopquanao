<?php


final class SizeService
{
    public function __construct(private PDO $pdo)
    {
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

        $orderBy = $this->isSqlite()
            ? "ORDER BY category_id, CASE size_name WHEN 'S' THEN 1 WHEN 'M' THEN 2 WHEN 'L' THEN 3 WHEN 'XL' THEN 4 ELSE 5 END"
            : "ORDER BY category_id, FIELD(size_name, 'S','M','L','XL')";
        if ($catId > 0) {
            $stmt = $this->pdo->prepare("SELECT * FROM size_guides WHERE category_id = ? $orderBy");
            $stmt->execute([$catId]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM size_guides $orderBy");
        }
        $sizes = $this->dedupeSizeRows($stmt->fetchAll(PDO::FETCH_ASSOC));
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

    private function isSqlite(): bool {
        try {
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (Throwable $e) {
            return false;
        }
    }
}
