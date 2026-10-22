<?php

final readonly class OutfitRequest
{
    /**
     * @param list<string> $tags requested StyleTag values (may be [] with an anchor)
     * @param array<int,string> $sizeByCategory category_id => size
     */
    public function __construct(
        public array $tags,
        public ?int $budget = null,
        public array $sizeByCategory = [],
        public ?int $anchorProductId = null,
    ) {
    }

    public function sizeFor(int $categoryId): ?string
    {
        return $this->sizeByCategory[$categoryId] ?? null;
    }
}
