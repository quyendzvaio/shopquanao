<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitBuilderTest extends TestCase
{
    /** @param list<string> $tags @param list<string> $sizes */
    private function item(int $id, string $slot, int $price, array $tags, int $stock = 5, array $sizes = ['M'], bool $statement = false): OutfitItem
    {
        $category = [OutfitSlot::TOP => 1, OutfitSlot::BOTTOM => 2, OutfitSlot::DRESS => 3, OutfitSlot::ACCESSORY => 4][$slot];
        return new OutfitItem($id, "SP$id", $price, $stock, $category, $slot, $tags, $slot === OutfitSlot::ACCESSORY ? [] : $sizes, $statement);
    }

    /** @return list<int> */
    private function ids(OutfitSet $set): array
    {
        return array_map(fn(OutfitItem $i) => $i->id, $set->items);
    }

    private function interviewCatalog(): array
    {
        return [
            $this->item(61, OutfitSlot::TOP, 250000, ['congso', 'phongvan', 'basic']),
            $this->item(53, OutfitSlot::TOP, 415000, ['congso', 'phongvan', 'mualanh']),
            $this->item(66, OutfitSlot::BOTTOM, 540000, ['congso', 'phongvan', 'dutiec']),
            $this->item(88, OutfitSlot::BOTTOM, 430000, ['congso', 'phongvan']),
            $this->item(67, OutfitSlot::BOTTOM, 310000, ['dichoi', 'dibien']),
            $this->item(93, OutfitSlot::ACCESSORY, 520000, ['congso', 'phongvan', 'basic']),
        ];
    }

    public function testBudgetPrefersTheSetThatUsesMostOfTheBudget(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['phongvan'], 800000));

        $this->assertNull($result->reason);
        $this->assertSame([61, 66], $this->ids($result->sets[0]));
        $this->assertSame(790000, $result->sets[0]->total);
        $this->assertSame([61, 88], $this->ids($result->sets[1]));
        foreach ($result->sets as $set) {
            $this->assertLessThanOrEqual(800000, $set->total);
        }
    }

    public function testWithoutBudgetCheaperSetWinsTiesAndAccessoryIsAdded(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['phongvan']));

        $this->assertSame([61, 88, 93], $this->ids($result->sets[0]));
    }

    public function testBudgetTooLowReportsCheapestValidTotal(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['phongvan'], 300000));

        $this->assertSame([], $result->sets);
        $this->assertSame(OutfitResult::BUDGET_TOO_LOW, $result->reason);
        $this->assertSame(680000, $result->cheapestTotal);
    }

    public function testOutOfStockAndWrongSizeItemsAreSkipped(): void
    {
        $catalog = [
            $this->item(1, OutfitSlot::TOP, 100000, ['dichoi'], stock: 0),
            $this->item(2, OutfitSlot::TOP, 100000, ['dichoi'], sizes: ['S']),
            $this->item(3, OutfitSlot::TOP, 200000, ['dichoi'], sizes: ['M', 'L']),
            $this->item(4, OutfitSlot::BOTTOM, 200000, ['dichoi'], sizes: ['L']),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dichoi'], null, [1 => 'L', 2 => 'L']));

        $this->assertSame([3, 4], $this->ids($result->sets[0]));
        $this->assertCount(1, $result->sets);
    }

    public function testAtMostOneStatementPiecePerSet(): void
    {
        $catalog = [
            $this->item(1, OutfitSlot::TOP, 100000, ['dichoi'], statement: true),
            $this->item(2, OutfitSlot::BOTTOM, 100000, ['dichoi'], statement: true),
            $this->item(3, OutfitSlot::BOTTOM, 150000, ['dichoi']),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dichoi']));

        $this->assertSame([1, 3], $this->ids($result->sets[0]));
        $this->assertCount(1, $result->sets);
    }

    public function testDressIsACompleteBase(): void
    {
        $catalog = [
            $this->item(80, OutfitSlot::DRESS, 700000, ['dutiec']),
            $this->item(96, OutfitSlot::ACCESSORY, 280000, ['dutiec', 'hendo']),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dutiec'], 1000000));

        $this->assertSame([80, 96], $this->ids($result->sets[0]));
        $this->assertSame(980000, $result->sets[0]->total);
    }

    public function testAnchorIsFixedInItsSlotAndLendsItsTags(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest([], 1000000, [], 53));

        $this->assertSame(['congso', 'phongvan', 'mualanh'], $result->tags);
        foreach ($result->sets as $set) {
            $this->assertContains(53, $this->ids($set));
        }
    }

    public function testUnavailableAnchorAndMissingTagsAreReported(): void
    {
        $catalog = [$this->item(50, OutfitSlot::TOP, 180000, ['basic'], stock: 0)];
        $builder = new OutfitBuilder();

        $this->assertSame(OutfitResult::ANCHOR_UNAVAILABLE, $builder->build($catalog, new OutfitRequest([], null, [], 50))->reason);
        $this->assertSame(OutfitResult::ANCHOR_UNAVAILABLE, $builder->build($catalog, new OutfitRequest([], null, [], 999))->reason);
        $this->assertSame(OutfitResult::MISSING_TAGS, $builder->build($catalog, new OutfitRequest([]))->reason);
    }

    public function testNoItemsForTagsIsReported(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['thethao']));

        $this->assertSame(OutfitResult::NO_ITEMS_FOR_TAGS, $result->reason);
    }

    public function testSecondSetDiffersInAtLeastOneMainItem(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['phongvan']));

        $this->assertCount(2, $result->sets);
        $this->assertNotSame($result->sets[0]->mainIds(), $result->sets[1]->mainIds());
    }
}
