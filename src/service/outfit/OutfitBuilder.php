<?php

/**
 * Deterministic outfit composer (spec §5.4). Bases are top+bottom or a
 * dress; one accessory is added when it fits the budget and palette.
 * The catalog is small (tens of items), so combinations are enumerated.
 */
final class OutfitBuilder
{
    public const MAX_COMBINATIONS = 5000;
    public const MAX_SETS = 2;

    /** @param list<OutfitItem> $catalog */
    public function build(array $catalog, OutfitRequest $request): OutfitResult
    {
        $tags = array_values(array_slice($request->tags, 0, StyleTag::MAX_PER_REQUEST));
        $anchor = null;
        if ($request->anchorProductId !== null) {
            foreach ($catalog as $item) {
                if ($item->id === $request->anchorProductId) $anchor = $item;
            }
            if ($anchor === null || $anchor->stock <= 0) {
                return new OutfitResult([], $tags, OutfitResult::ANCHOR_UNAVAILABLE);
            }
            if ($tags === []) $tags = $anchor->tags;
        }
        if ($tags === []) {
            return new OutfitResult([], [], OutfitResult::MISSING_TAGS);
        }

        $bySlot = [OutfitSlot::TOP => [], OutfitSlot::BOTTOM => [], OutfitSlot::DRESS => [], OutfitSlot::ACCESSORY => []];
        foreach ($catalog as $item) {
            if ($anchor !== null && $item->id === $anchor->id) continue;
            if ($this->eligible($item, $tags, $request)) $bySlot[$item->slot][] = $item;
        }
        $hasCandidates = array_filter($bySlot) !== [];
        $forcedAccessory = null;
        if ($anchor !== null) {
            if ($anchor->slot === OutfitSlot::ACCESSORY) {
                $forcedAccessory = $anchor;
            } else {
                $bySlot[$anchor->slot] = [$anchor];
                if ($anchor->slot === OutfitSlot::DRESS) {
                    $bySlot[OutfitSlot::TOP] = [];
                    $bySlot[OutfitSlot::BOTTOM] = [];
                } else {
                    $bySlot[OutfitSlot::DRESS] = [];
                }
            }
        }

        $sets = [];
        $cheapest = null;
        foreach ($this->bases($bySlot) as $base) {
            if ($forcedAccessory !== null) $base[] = $forcedAccessory;
            if (!$this->paletteOk($base)) continue;
            $total = $this->total($base);
            $cheapest = $cheapest === null ? $total : min($cheapest, $total);
            if ($request->budget !== null && $total > $request->budget) continue;
            if ($forcedAccessory === null) {
                $base = $this->withBestAccessory($base, $bySlot[OutfitSlot::ACCESSORY], $tags, $request->budget);
            }
            $sets[] = $this->set($base, $tags);
        }

        if ($sets === []) {
            if (!$hasCandidates && $anchor === null) {
                return new OutfitResult([], $tags, OutfitResult::NO_ITEMS_FOR_TAGS);
            }
            if ($request->budget !== null && $cheapest !== null && $cheapest > $request->budget) {
                return new OutfitResult([], $tags, OutfitResult::BUDGET_TOO_LOW, $cheapest);
            }
            return new OutfitResult([], $tags, OutfitResult::NO_COMPATIBLE_SET);
        }

        $preferHigherTotal = $request->budget !== null;
        usort($sets, function (OutfitSet $a, OutfitSet $b) use ($preferHigherTotal): int {
            return [$b->score, $preferHigherTotal ? $b->total : $a->total, $a->ids()]
                <=> [$a->score, $preferHigherTotal ? $a->total : $b->total, $b->ids()];
        });

        $picked = [$sets[0]];
        foreach ($sets as $set) {
            if (count($picked) >= self::MAX_SETS) break;
            if ($set->mainIds() !== $sets[0]->mainIds()) $picked[] = $set;
        }
        return new OutfitResult($picked, $tags);
    }

    /** @param list<string> $tags */
    private function eligible(OutfitItem $item, array $tags, OutfitRequest $request): bool
    {
        return $item->stock > 0
            && array_intersect($item->tags, $tags) !== []
            && $item->fitsSize($request->sizeFor($item->categoryId));
    }

    /**
     * @param array<string,list<OutfitItem>> $bySlot
     * @return iterable<list<OutfitItem>>
     */
    private function bases(array $bySlot): iterable
    {
        $count = 0;
        foreach ($bySlot[OutfitSlot::DRESS] as $dress) {
            if (++$count > self::MAX_COMBINATIONS) return;
            yield [$dress];
        }
        foreach ($bySlot[OutfitSlot::TOP] as $top) {
            foreach ($bySlot[OutfitSlot::BOTTOM] as $bottom) {
                if (++$count > self::MAX_COMBINATIONS) return;
                yield [$top, $bottom];
            }
        }
    }

    /**
     * @param list<OutfitItem> $base
     * @param list<OutfitItem> $accessories
     * @param list<string> $tags
     * @return list<OutfitItem>
     */
    private function withBestAccessory(array $base, array $accessories, array $tags, ?int $budget): array
    {
        $best = $base;
        $bestScore = $this->score($base, $tags);
        $chosen = null;
        foreach ($accessories as $accessory) {
            $candidate = [...$base, $accessory];
            if (!$this->paletteOk($candidate)) continue;
            if ($budget !== null && $this->total($candidate) > $budget) continue;
            $score = $this->score($candidate, $tags);
            if ($score > $bestScore || ($score === $bestScore && $chosen !== null && $this->accessoryWins($accessory, $chosen, $budget !== null))) {
                $best = $candidate;
                $bestScore = $score;
                $chosen = $accessory;
            }
        }
        return $best;
    }

    /** Equal score: with a budget the pricier accessory, without one the cheaper; then lower id. */
    private function accessoryWins(OutfitItem $candidate, OutfitItem $current, bool $hasBudget): bool
    {
        if ($candidate->price !== $current->price) {
            return $hasBudget ? $candidate->price > $current->price : $candidate->price < $current->price;
        }
        return $candidate->id < $current->id;
    }

    /** @param list<OutfitItem> $items */
    private function paletteOk(array $items): bool
    {
        return count(array_filter($items, fn(OutfitItem $item) => $item->statement)) <= 1;
    }

    /** @param list<OutfitItem> $items */
    private function total(array $items): int
    {
        return array_sum(array_map(fn(OutfitItem $item) => $item->price, $items));
    }

    /**
     * 10 per requested tag each item carries, 3 per tag shared by a pair of
     * items (cohesion), 1 per neutral item.
     *
     * @param list<OutfitItem> $items
     * @param list<string> $tags
     */
    private function score(array $items, array $tags): int
    {
        $score = 0;
        foreach ($items as $i => $item) {
            $score += 10 * count(array_intersect($item->tags, $tags));
            if (!$item->statement) $score += 1;
            for ($j = $i + 1; $j < count($items); $j++) {
                $score += 3 * count(array_intersect($item->tags, $items[$j]->tags));
            }
        }
        return $score;
    }

    /**
     * @param list<OutfitItem> $items
     * @param list<string> $tags
     */
    private function set(array $items, array $tags): OutfitSet
    {
        $carried = array_merge(...array_map(fn(OutfitItem $item) => $item->tags, $items));
        $matched = array_values(array_filter($tags, fn(string $tag) => in_array($tag, $carried, true)));
        return new OutfitSet($items, $this->total($items), $this->score($items, $tags), $matched);
    }
}
