<?php

final readonly class OutfitSet
{
    /**
     * @param list<OutfitItem> $items
     * @param list<string> $matchedTags
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $score,
        public array $matchedTags,
    ) {
    }

    /** @return list<int> sorted ids of non-accessory items */
    public function mainIds(): array
    {
        $ids = [];
        foreach ($this->items as $item) {
            if ($item->slot !== OutfitSlot::ACCESSORY) $ids[] = $item->id;
        }
        sort($ids);
        return $ids;
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map(fn(OutfitItem $item) => $item->id, $this->items);
    }
}
