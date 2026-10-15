<?php

/**
 * Picks in-stock products that lift a cart over the free-shipping threshold
 * with the smallest overshoot (spec §6.2). Pure: callers supply cart + catalog.
 */
final class FreeShippingGapAdvisor
{
    public const QUALIFIED = 'qualified';
    public const EMPTY_CART = 'empty_cart';
    public const GAP = 'gap';

    /**
     * @param list<array{product_id:int,quantity:int,size?:?string}> $cartItems
     * @param list<OutfitItem> $catalog
     * @return array{status:string,threshold:int,subtotal:int,gap:int,suggestions:list<OutfitItem>}
     */
    public function advise(array $cartItems, int $subtotal, array $catalog, FreeShippingPolicy $policy, int $limit = 3): array
    {
        $gap = max(0, $policy->threshold - $subtotal);
        $base = ['threshold' => $policy->threshold, 'subtotal' => $subtotal, 'gap' => $gap, 'suggestions' => []];
        if ($cartItems === []) return ['status' => self::EMPTY_CART] + $base;
        if ($gap === 0) return ['status' => self::QUALIFIED] + $base;

        $byId = [];
        foreach ($catalog as $item) $byId[$item->id] = $item;
        $inCart = array_map(fn(array $row) => (int)$row['product_id'], $cartItems);
        $cartTags = [];
        foreach ($inCart as $id) {
            if (isset($byId[$id])) $cartTags = array_merge($cartTags, $byId[$id]->tags);
        }
        $size = $this->commonClothingSize($cartItems, $byId);

        $candidates = array_values(array_filter($catalog, fn(OutfitItem $item) => $item->stock > 0
            && !in_array($item->id, $inCart, true)
            && $item->price >= $gap
            && $item->fitsSize($size)));
        usort($candidates, fn(OutfitItem $a, OutfitItem $b) => [
            $a->price - $gap, -count(array_intersect($a->tags, $cartTags)), $a->id,
        ] <=> [
            $b->price - $gap, -count(array_intersect($b->tags, $cartTags)), $b->id,
        ]);

        return ['status' => self::GAP, 'suggestions' => array_slice($candidates, 0, max(1, $limit))] + $base;
    }

    /**
     * The shopper's size is only known when every sized cart item agrees.
     *
     * @param list<array{product_id:int,quantity:int,size?:?string}> $cartItems
     * @param array<int,OutfitItem> $byId
     */
    private function commonClothingSize(array $cartItems, array $byId): ?string
    {
        $sizes = [];
        foreach ($cartItems as $row) {
            $item = $byId[(int)$row['product_id']] ?? null;
            $size = strtoupper(trim((string)($row['size'] ?? '')));
            if ($item !== null && $item->slot !== OutfitSlot::ACCESSORY && $size !== '') $sizes[$size] = true;
        }
        return count($sizes) === 1 ? (string)array_key_first($sizes) : null;
    }
}
