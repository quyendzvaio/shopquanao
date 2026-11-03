<?php

/** Tool `suggest_freeship_items`: read-only, never mutates the cart. */
final class FreeShippingService
{
    public function __construct(private PDO $pdo, private FreeShippingPolicy $policy)
    {
    }

    /** @return array<string,mixed> */
    public function suggest(?int $userId, array $arguments): array
    {
        $policy = $this->policy;
        if ($userId === null) {
            return ['status' => 'requires_login', 'threshold' => $policy->threshold, 'products' => []];
        }

        $cart = (new CartService($this->pdo))->list($userId);
        $items = array_map(fn(array $row) => [
            'product_id' => (int)$row['product_id'],
            'quantity' => (int)$row['quantity'],
            'size' => isset($row['size']) ? (string)$row['size'] : null,
        ], $cart['cart']);
        $advice = (new FreeShippingGapAdvisor())->advise(
            $items,
            (int)round((float)$cart['total']),
            (new OutfitCatalogRepository($this->pdo))->all(),
            $policy,
            max(1, min(5, (int)($arguments['limit'] ?? 3)))
        );

        return [
            'status' => $advice['status'],
            'threshold' => $advice['threshold'],
            'subtotal' => $advice['subtotal'],
            'gap' => $advice['gap'],
            'cart_fingerprint' => self::fingerprint($items),
            'products' => array_map(fn(OutfitItem $item) => $item->toProduct(), $advice['suggestions']),
        ];
    }

    /** @param list<array{product_id:int,quantity:int,size:?string}> $items */
    public static function fingerprint(array $items): string
    {
        $parts = array_map(fn(array $item) => $item['product_id'] . ':' . ($item['size'] ?? '') . ':' . $item['quantity'], $items);
        sort($parts);
        return sha1(implode('|', $parts));
    }
}
