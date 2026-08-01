<?php

class ProductAttributeNormalizer {
    private const COLOR_ALIASES = [
        'đen' => ['đen', 'den', 'black'],
        'trắng' => ['trắng', 'trang', 'white'],
        'xanh' => ['xanh', 'blue', 'green'],
        'đỏ' => ['đỏ', 'do', 'red'],
        'hồng' => ['hồng', 'hong', 'pink'],
        'xám' => ['xám', 'xam', 'ghi', 'gray', 'grey'],
        'nâu' => ['nâu', 'nau', 'brown'],
        'be' => ['be', 'beige', 'kem', 'cream'],
        'vàng' => ['vàng', 'vang', 'yellow'],
        'tím' => ['tím', 'tim', 'purple'],
        'cam' => ['cam', 'orange'],
    ];

    private const TEXT_ALIASES = [
        'cotton' => ['cotton'],
        'linen' => ['linen'],
        'len' => ['len', 'wool'],
        'kaki' => ['kaki'],
        'jean' => ['jean', 'jeans', 'denim'],
        'voan' => ['voan'],
        'lụa' => ['lụa', 'lua', 'satin', 'silk'],
        'da' => ['da', 'leather'],
        'công sở' => ['công sở', 'cong so', 'đi làm', 'di lam', 'formal', 'lịch sự', 'lich su', 'interview', 'phỏng vấn', 'phong van'],
        'thể thao' => ['thể thao', 'the thao', 'sport', 'active'],
        'vintage' => ['vintage'],
        'form rộng' => ['form rộng', 'form rong', 'oversize', 'rộng', 'rong'],
        'ôm' => ['ôm', 'om', 'slimfit', 'slim-fit', 'slim fit'],
        'basic' => ['basic'],
        'trẻ trung' => ['trẻ', 'tre', 'trẻ trung', 'tre trung', 'youthful'],
    ];

    public static function normalizeColor(?string $color): ?string {
        $raw = mb_strtolower(trim((string)$color));
        $value = self::normalizeText($raw);
        if ($value === '') {
            return null;
        }

        if ($value === 'tim' || preg_match('/\btím\b|\bpurple\b|\b(?:màu|mau)\s+tim\b/ui', $raw)) {
            return 'tím';
        }

        foreach (self::COLOR_ALIASES as $canonical => $aliases) {
            if ($canonical === 'tím') {
                continue;
            }
            foreach ($aliases as $alias) {
                if (self::containsToken($value, self::normalizeText($alias))) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    public static function colorAliases(string $color): array {
        $canonical = self::normalizeColor($color) ?? $color;
        return self::COLOR_ALIASES[$canonical] ?? [$canonical];
    }

    public static function normalizeSize(?string $size): ?string {
        $value = strtoupper(trim((string)$size));
        if ($value === '') {
            return null;
        }
        return in_array($value, ['XS', 'S', 'M', 'L', 'XL', 'XXL'], true) ? $value : null;
    }

    public static function extractColorsFromProduct(array $product): array {
        $text = self::productText($product);
        $colors = [];
        foreach (array_keys(self::COLOR_ALIASES) as $color) {
            if (self::textMatchesColor($text, $color)) {
                $colors[] = $color;
            }
        }
        return array_values(array_unique($colors));
    }

    public static function productMatchesConstraints(array $product, array $constraints): bool {
        if (isset($constraints['category_id']) && $constraints['category_id'] !== null && $constraints['category_id'] !== '') {
            if ((int)($product['category_id'] ?? 0) !== (int)$constraints['category_id']) {
                return false;
            }
        }

        if (isset($constraints['min_price']) && (float)($product['price'] ?? -1) < (float)$constraints['min_price']) {
            return false;
        }
        if (isset($constraints['max_price']) && (float)($product['price'] ?? PHP_FLOAT_MAX) > (float)$constraints['max_price']) {
            return false;
        }
        if (($constraints['in_stock'] ?? null) === true && (int)($product['stock'] ?? 0) <= 0) {
            return false;
        }

        if (!empty($constraints['size'])) {
            $requestedSize = self::normalizeSize((string)$constraints['size']);
            $availableSizes = self::productSizes($product);
            if ($requestedSize !== null && !in_array($requestedSize, $availableSizes, true)) {
                return false;
            }
        }

        $text = self::productText($product);
        if (!empty($constraints['color']) && !self::textMatchesColor($text, (string)$constraints['color'])) {
            return false;
        }

        foreach (['material', 'style', 'occasion', 'semantic_query'] as $field) {
            if (!empty($constraints[$field]) && !self::textMatchesAny($text, $constraints[$field])) {
                return false;
            }
        }

        if (!empty($constraints['avoid']) && self::textMatchesAny($text, $constraints['avoid'])) {
            return false;
        }

        return true;
    }

    public static function textMatchesColor(string $text, string $color): bool {
        $canonical = self::normalizeColor($color);
        if ($canonical === null) {
            return false;
        }

        if ($canonical === 'tím') {
            return preg_match('/\btím\b|\bpurple\b|\b(?:màu|mau)\s+tim\b/ui', mb_strtolower($text)) === 1;
        }

        $normalizedText = self::normalizeText($text);
        foreach (self::colorAliases($canonical) as $alias) {
            if (self::containsToken($normalizedText, self::normalizeText($alias))) {
                return true;
            }
        }
        return false;
    }

    public static function textMatchesAny(string $text, mixed $value): bool {
        $terms = self::terms($value);
        if ($terms === []) {
            return true;
        }

        $normalizedText = self::normalizeText($text);
        foreach ($terms as $term) {
            foreach (self::termAliases($term) as $alias) {
                if (self::containsToken($normalizedText, self::normalizeText($alias))) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function productText(array $product): string {
        return trim((string)($product['name'] ?? '') . ' ' . (string)($product['description'] ?? ''));
    }

    public static function productSizes(array $product): array {
        $sizes = [];
        foreach (($product['sizes'] ?? $product['available_sizes'] ?? []) as $size) {
            if (is_array($size) && isset($size['size_name'])) {
                $normalized = self::normalizeSize((string)$size['size_name']);
            } else {
                $normalized = self::normalizeSize((string)$size);
            }
            if ($normalized !== null) {
                $sizes[] = $normalized;
            }
        }
        return array_values(array_unique($sizes));
    }

    public static function normalizeText(string $text): string {
        $text = mb_strtolower(trim($text));
        $map = [
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
            'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
            'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
            'đ'=>'d',
        ];
        $text = strtr($text, $map);
        return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    }

    private static function termAliases(string $term): array {
        $normalized = self::normalizeText($term);
        foreach (self::TEXT_ALIASES as $canonical => $aliases) {
            if ($normalized === self::normalizeText($canonical) || in_array($normalized, array_map([self::class, 'normalizeText'], $aliases), true)) {
                return $aliases;
            }
        }
        return [$term];
    }

    private static function terms(mixed $value): array {
        if (is_array($value)) {
            $terms = [];
            foreach ($value as $item) {
                $terms = array_merge($terms, self::terms($item));
            }
            return array_values(array_filter($terms, fn($term) => $term !== ''));
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return [];
        }
        $terms = [$raw];
        foreach (preg_split('/[,;|]+/u', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $terms[] = $part;
            }
        }
        foreach (preg_split('/[\s_]+/u', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $terms[] = $part;
            }
        }
        return array_values(array_unique($terms));
    }

    private static function containsToken(string $haystack, string $needle): bool {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }
        return preg_match('/(^|\s)' . preg_quote($needle, '/') . '(\s|$)/u', $haystack) === 1;
    }
}
