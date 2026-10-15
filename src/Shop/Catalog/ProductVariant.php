<?php

final readonly class ProductVariant
{
    public function __construct(
        public int $id,
        public int $productId,
        public string $variantKey,
        public ?string $sku,
        public ?CatalogColor $color,
        public ?string $size,
        public ?float $price,
        public ?int $stock,
        public bool $active
    ) {
        if ($id <= 0 || $productId <= 0 || trim($variantKey) === '') {
            throw new InvalidArgumentException('Invalid product variant');
        }
        if ($price !== null && $price < 0) throw new InvalidArgumentException('Variant price cannot be negative');
        if ($stock !== null && $stock < 0) throw new InvalidArgumentException('Variant stock cannot be negative');
    }

    public function toArray(float $basePrice, int $baseStock): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'color' => $this->color?->toArray(),
            'size' => $this->size,
            'price' => $this->price ?? $basePrice,
            'stock' => $this->stock ?? $baseStock,
            'inherits_price' => $this->price === null,
            'inherits_stock' => $this->stock === null,
            'is_active' => $this->active,
        ];
    }
}
