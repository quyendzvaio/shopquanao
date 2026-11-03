<?php

/**
 * Normalised filters of the REST product list (ProductRepository::listPage).
 * A null field means "no filter". $likeTerm is the full-phrase LIKE pattern and is set exactly
 * when there is a search term; $ftsQuery is the FULLTEXT boolean query of its words of 3+ chars;
 * $tokens are its words of 2+ chars, AND-matched when there are more than one.
 * $category is a category id (int) or a category canonical key (string).
 */
final readonly class ProductListFilter
{
    /** @param list<string> $tokens */
    public function __construct(
        public ?string $ftsQuery = null,
        public ?string $likeTerm = null,
        public array $tokens = [],
        public int|string|null $category = null,
        public ?string $subcategory = null,
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public bool $inStock = false,
        public ?string $color = null,
    ) {
    }
}
