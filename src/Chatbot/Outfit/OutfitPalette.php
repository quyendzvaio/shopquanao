<?php

/**
 * Colour rule for outfits: a set may contain at most one "statement" piece
 * (accent colour or print). Neutrals, navy and dark denim pair with anything.
 * Matching is on the accented product name so "Cổ Tim" is not "tím".
 */
final class OutfitPalette
{
    private const NEUTRAL_OVERRIDES = '/xanh\s+(?:navy|đậm)/u';
    private const STATEMENT = '/(?<!\p{L})(đỏ|hồng|xanh|vàng|tím|cam|rêu|caro|họa\s+tiết|graphic|phối\s+màu)(?!\p{L})/u';

    public static function isStatement(string $name): bool
    {
        $lower = (string)preg_replace(self::NEUTRAL_OVERRIDES, ' ', mb_strtolower($name));
        return preg_match(self::STATEMENT, $lower) === 1;
    }
}
