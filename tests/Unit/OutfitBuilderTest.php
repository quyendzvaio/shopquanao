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
        $this->assertSame([53, 66], $this->ids($result->sets[0]));
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

    private function baseWithAccessories(array $accessories): array
    {
        return [
            $this->item(1, OutfitSlot::TOP, 100000, ['dichoi']),
            $this->item(2, OutfitSlot::BOTTOM, 100000, ['dichoi']),
            ...$accessories,
        ];
    }

    public function testAccessoryTieWithBudgetPrefersPricierThenLowerId(): void
    {
        $catalog = $this->baseWithAccessories([
            $this->item(11, OutfitSlot::ACCESSORY, 50000, ['dichoi']),
            $this->item(12, OutfitSlot::ACCESSORY, 90000, ['dichoi']),
            $this->item(14, OutfitSlot::ACCESSORY, 90000, ['dichoi']),
            $this->item(13, OutfitSlot::ACCESSORY, 90000, ['dichoi']),
        ]);
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dichoi'], 500000));

        $this->assertSame([1, 2, 12], $this->ids($result->sets[0]));
    }

    public function testAccessoryTieWithoutBudgetPrefersCheaperThenLowerId(): void
    {
        $catalog = $this->baseWithAccessories([
            $this->item(14, OutfitSlot::ACCESSORY, 50000, ['dichoi']),
            $this->item(13, OutfitSlot::ACCESSORY, 50000, ['dichoi']),
            $this->item(12, OutfitSlot::ACCESSORY, 90000, ['dichoi']),
        ]);
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dichoi']));

        $this->assertSame([1, 2, 13], $this->ids($result->sets[0]));
    }

    public function testAccessoryAnchorIsInEverySetAndCountsTowardBudget(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest([], 1300000, [], 93));

        $this->assertNull($result->reason);
        $this->assertNotSame([], $result->sets);
        foreach ($result->sets as $set) {
            $this->assertContains(93, $this->ids($set));
            $this->assertLessThanOrEqual(1300000, $set->total);
        }
        // 93 (520k) + cheapest base 61+88 (680k) = 1.2M fits; 61+66 (790k) = 1.31M does not.
        $this->assertSame([61, 88, 93], $this->ids($result->sets[0]));

        $tight = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest([], 1100000, [], 93));
        $this->assertSame(OutfitResult::BUDGET_TOO_LOW, $tight->reason);
        $this->assertSame(1200000, $tight->cheapestTotal);
    }

    public function testAccessoryAnchorCountsTowardPalette(): void
    {
        $catalog = [
            $this->item(1, OutfitSlot::TOP, 100000, ['dichoi'], statement: true),
            $this->item(2, OutfitSlot::BOTTOM, 100000, ['dichoi']),
            $this->item(3, OutfitSlot::TOP, 120000, ['dichoi']),
            $this->item(9, OutfitSlot::ACCESSORY, 50000, ['dichoi'], statement: true),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest([], null, [], 9));

        $this->assertCount(1, $result->sets);
        $this->assertSame([3, 2, 9], $this->ids($result->sets[0]));
    }

    public function testDressAnchorYieldsDressSetsOnly(): void
    {
        $catalog = [
            $this->item(80, OutfitSlot::DRESS, 700000, ['dutiec']),
            $this->item(81, OutfitSlot::TOP, 200000, ['dutiec']),
            $this->item(82, OutfitSlot::BOTTOM, 200000, ['dutiec']),
            $this->item(96, OutfitSlot::ACCESSORY, 280000, ['dutiec']),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest([], null, [], 80));

        $this->assertCount(1, $result->sets);
        $this->assertSame([80, 96], $this->ids($result->sets[0]));
    }

    public function testTopAnchorWithoutEligibleBottomsHasNoCompatibleSet(): void
    {
        $catalog = [
            $this->item(1, OutfitSlot::TOP, 100000, ['dichoi']),
            $this->item(2, OutfitSlot::BOTTOM, 100000, ['dichoi'], stock: 0),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest([], null, [], 1));

        $this->assertSame([], $result->sets);
        $this->assertSame(OutfitResult::NO_COMPATIBLE_SET, $result->reason);
    }

    public function testStatementAccessoryIsNotAddedToStatementBase(): void
    {
        $catalog = [
            $this->item(1, OutfitSlot::TOP, 100000, ['dichoi'], statement: true),
            $this->item(2, OutfitSlot::BOTTOM, 100000, ['dichoi']),
            $this->item(9, OutfitSlot::ACCESSORY, 50000, ['dichoi'], statement: true),
            $this->item(8, OutfitSlot::ACCESSORY, 70000, ['dichoi']),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dichoi']));

        $this->assertSame([1, 2, 8], $this->ids($result->sets[0]));

        $only = (new OutfitBuilder())->build([$catalog[0], $catalog[1], $catalog[2]], new OutfitRequest(['dichoi']));
        $this->assertSame([1, 2], $this->ids($only->sets[0]));
    }

    public function testCombinationCapKeepsLargeCatalogsBoundedAndValid(): void
    {
        $catalog = [];
        for ($i = 1; $i <= 80; $i++) {
            $catalog[] = $this->item($i, OutfitSlot::TOP, 100000 + $i, ['dichoi']);
            $catalog[] = $this->item(1000 + $i, OutfitSlot::BOTTOM, 100000 + $i, ['dichoi']);
        }
        $this->assertGreaterThan(OutfitBuilder::MAX_COMBINATIONS, 80 * 80);

        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dichoi']));

        $this->assertNull($result->reason);
        $this->assertLessThanOrEqual(OutfitBuilder::MAX_SETS, count($result->sets));
        $this->assertNotSame([], $result->sets);
    }
}
