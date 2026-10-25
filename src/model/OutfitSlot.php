<?php

/** Position a product takes in an outfit, derived from its category. */
final class OutfitSlot
{
    public const TOP = 'top';
    public const BOTTOM = 'bottom';
    public const DRESS = 'dress';
    public const ACCESSORY = 'accessory';

    /**
     * Category 3 mixes skirts (worn with a top) and one-piece dresses;
     * product_subcategories has no rows for it, so the name decides.
     */
    public static function resolve(int $categoryId, string $name): ?string
    {
        return match ($categoryId) {
            1 => self::TOP,
            2 => self::BOTTOM,
            3 => str_starts_with(mb_strtolower(trim($name)), 'chân váy') ? self::BOTTOM : self::DRESS,
            4 => self::ACCESSORY,
            default => null,
        };
    }
}
