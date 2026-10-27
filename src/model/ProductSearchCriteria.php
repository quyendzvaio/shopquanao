<?php

/**
 * Normalised SQL filters of the agent product search (ProductRepository::search).
 * A null field means "no filter"; size and color are already canonical.
 */
final readonly class ProductSearchCriteria
{
    public function __construct(
        public ?int $categoryId = null,
        public ?string $subcategory = null,
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public ?bool $inStock = null,
        public ?string $size = null,
        public ?string $color = null,
    ) {
    }
}
