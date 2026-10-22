<?php

/**
 * Closed vocabulary of occasion/style tags stored in products.style_tags
 * (comma-separated, 1-3 per product). The same list drives the chat parser,
 * the LLM enum, the admin form and the migration check.
 */
final class StyleTag
{
    public const ALL = [
        'congso', 'phongvan', 'dichoi', 'dibien', 'hendo',
        'dutiec', 'thethao', 'streetstyle', 'basic', 'mualanh',
    ];

    public const MAX_PER_PRODUCT = 3;

    /** Tags a single chat request may ask for. */
    public const MAX_PER_REQUEST = 2;

    private const LABELS = [
        'congso' => 'công sở',
        'phongvan' => 'phỏng vấn',
        'dichoi' => 'đi chơi',
        'dibien' => 'đi biển',
        'hendo' => 'hẹn hò',
        'dutiec' => 'dự tiệc',
        'thethao' => 'thể thao',
        'streetstyle' => 'streetstyle',
        'basic' => 'basic',
        'mualanh' => 'mùa lạnh',
    ];

    /**
     * Letter boundaries keep "date" out of "update". Unaccented "mua ..."
     * variants are omitted on purpose: "mua" also means "buy".
     */
    public const PATTERNS = [
        'phongvan' => '/(?<!\p{L})(phỏng\s+vấn|phong\s+van|interview)(?!\p{L})/u',
        'congso' => '/(?<!\p{L})(công\s+sở|cong\s+so|đi\s+làm|di\s+lam|văn\s+phòng|van\s+phong)(?!\p{L})/u',
        'dichoi' => '/(?<!\p{L})(đi\s+chơi|di\s+choi|dạo\s+phố|dao\s+pho|cà\s+phê|ca\s+phe|cafe)(?!\p{L})/u',
        'dibien' => '/(?<!\p{L})(đi\s+biển|di\s+bien|du\s+lịch|du\s+lich|mùa\s+hè|đi\s+bơi)(?!\p{L})/u',
        'hendo' => '/(?<!\p{L})(hẹn\s+hò|hen\s+ho|date)(?!\p{L})/u',
        'dutiec' => '/(?<!\p{L})(dự\s+tiệc|du\s+tiec|đi\s+tiệc|tiệc|đám\s+cưới|dam\s+cuoi|sự\s+kiện)(?!\p{L})/u',
        'thethao' => '/(?<!\p{L})(thể\s+thao|the\s+thao|tập\s+gym|tap\s+gym|gym|chạy\s+bộ|chay\s+bo)(?!\p{L})/u',
        'streetstyle' => '/(?<!\p{L})(streetstyle|street\s+style|đường\s+phố|duong\s+pho|cá\s+tính|ca\s+tinh)(?!\p{L})/u',
        'basic' => '/(?<!\p{L})(basic|tối\s+giản|toi\s+gian|đơn\s+giản|don\s+gian)(?!\p{L})/u',
        'mualanh' => '/(?<!\p{L})(mùa\s+đông|mùa\s+lạnh|trời\s+lạnh|troi\s+lanh|giữ\s+ấm|giu\s+am)(?!\p{L})/u',
    ];

    public static function isValid(mixed $tag): bool
    {
        return is_string($tag) && in_array($tag, self::ALL, true);
    }

    public static function label(string $tag): string
    {
        return self::LABELS[$tag] ?? $tag;
    }

    /** @return list<string> */
    public static function parseList(?string $csv): array
    {
        $tags = array_map('trim', explode(',', (string)$csv));
        $tags = array_values(array_unique(array_filter($tags, [self::class, 'isValid'])));
        return array_slice($tags, 0, self::MAX_PER_PRODUCT);
    }

    /** @param array<mixed> $tags admin form input */
    public static function toColumn(array $tags): string
    {
        return implode(',', self::parseList(implode(',', array_map('strval', $tags))));
    }

    /** @return list<string> tags mentioned in the text, in order of appearance */
    public static function fromText(string $text): array
    {
        $lower = mb_strtolower($text);
        $positions = [];
        foreach (self::PATTERNS as $tag => $pattern) {
            if (preg_match($pattern, $lower, $m, PREG_OFFSET_CAPTURE) === 1) {
                $positions[$tag] = (int)$m[0][1];
            }
        }
        asort($positions);
        return array_slice(array_keys($positions), 0, self::MAX_PER_REQUEST);
    }
}
