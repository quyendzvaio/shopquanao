<?php

/** Catalog product as the outfit builder sees it. Prices are whole VND. */
final readonly class OutfitItem
{
    /**
     * @param list<string> $tags
     * @param list<string> $sizes upper-case sizes in stock; [] = not sized
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $price,
        public int $stock,
        public int $categoryId,
        public string $slot,
        public array $tags,
        public array $sizes,
        public bool $statement,
        public string $image = '',
    ) {
    }

    /** Accessories and products without size data never block a size filter. */
    public function fitsSize(?string $size): bool
    {
        if ($size === null || $this->slot === OutfitSlot::ACCESSORY || $this->sizes === []) {
            return true;
        }
        return in_array(strtoupper($size), $this->sizes, true);
    }

    /** @return array<string,mixed> product shape consumed by EvidenceNormalizer */
    public function toProduct(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'stock' => $this->stock,
            'category_id' => $this->categoryId,
            'image' => $this->image,
            'available_sizes' => $this->sizes,
            'style_tags' => $this->tags,
        ];
    }
}
