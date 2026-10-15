<?php

final readonly class OutfitResult
{
    public const MISSING_TAGS = 'missing_tags';
    public const ANCHOR_UNAVAILABLE = 'anchor_unavailable';
    public const NO_ITEMS_FOR_TAGS = 'no_items_for_tags';
    public const BUDGET_TOO_LOW = 'budget_too_low';
    public const NO_COMPATIBLE_SET = 'no_compatible_set';

    /**
     * @param list<OutfitSet> $sets best first, at most OutfitBuilder::MAX_SETS
     * @param list<string> $tags tags actually used (anchor tags when none asked)
     */
    public function __construct(
        public array $sets,
        public array $tags,
        public ?string $reason = null,
        public ?int $cheapestTotal = null,
    ) {
    }
}
