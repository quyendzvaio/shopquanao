<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingGapAdvisorTest extends TestCase
{
    private function item(int $id, int $price, array $tags = ['basic'], int $stock = 5, string $slot = OutfitSlot::TOP, array $sizes = ['M']): OutfitItem
    {
        $category = [OutfitSlot::TOP => 1, OutfitSlot::BOTTOM => 2, OutfitSlot::DRESS => 3, OutfitSlot::ACCESSORY => 4][$slot];
        return new OutfitItem($id, "SP$id", $price, $stock, $category, $slot, $tags, $slot === OutfitSlot::ACCESSORY ? [] : $sizes, false);
    }

    private function catalog(): array
    {
        return [
            $this->item(53, 415000, ['congso', 'phongvan', 'mualanh']),
            $this->item(99, 45000, ['dichoi', 'basic'], slot: OutfitSlot::ACCESSORY),
            $this->item(58, 210000, ['streetstyle', 'dichoi']),
            $this->item(61, 250000, ['congso', 'phongvan', 'basic']),
            $this->item(91, 150000, ['streetstyle'], stock: 0, slot: OutfitSlot::ACCESSORY),
            $this->item(96, 280000, ['dutiec', 'hendo'], slot: OutfitSlot::ACCESSORY),
            $this->item(77, 220000, ['basic'], sizes: ['S']),
        ];
    }

    public function testSuggestsClosestPriceAboveTheGap(): void
    {
        $advice = (new FreeShippingGapAdvisor())->advise(
            [['product_id' => 53, 'quantity' => 1, 'size' => 'M']],
            415000,
            $this->catalog(),
            new FreeShippingPolicy(500000, 200000)
        );

        $this->assertSame(FreeShippingGapAdvisor::GAP, $advice['status']);
        $this->assertSame(85000, $advice['gap']);
        // 99 is below the gap, 91 is out of stock, 77 lacks size M.
        $this->assertSame([58, 61, 96], array_map(fn(OutfitItem $i) => $i->id, $advice['suggestions']));
    }

    public function testEqualDistanceIsBrokenBySharedTagsWithTheCart(): void
    {
        $catalog = [
            $this->item(1, 300000, ['dutiec']),
            $this->item(2, 300000, ['congso']),
            $this->item(3, 200000, ['congso']),
        ];
        $advice = (new FreeShippingGapAdvisor())->advise(
            [['product_id' => 3, 'quantity' => 1, 'size' => 'M']],
            200000,
            $catalog,
            new FreeShippingPolicy(500000, 200000)
        );

        $this->assertSame([2, 1], array_map(fn(OutfitItem $i) => $i->id, $advice['suggestions']));
    }

    public function testQualifiedAndEmptyCarts(): void
    {
        $advisor = new FreeShippingGapAdvisor();
        $policy = new FreeShippingPolicy(500000, 200000);

        $this->assertSame(FreeShippingGapAdvisor::QUALIFIED, $advisor->advise([['product_id' => 53, 'quantity' => 2]], 830000, $this->catalog(), $policy)['status']);
        $this->assertSame(FreeShippingGapAdvisor::EMPTY_CART, $advisor->advise([], 0, $this->catalog(), $policy)['status']);
    }

    public function testPolicyReadsEnvironmentWithDefaults(): void
    {
        putenv('FREE_SHIPPING_THRESHOLD');
        putenv('FREESHIP_NUDGE_MAX_GAP=150000');
        $policy = FreeShippingPolicy::fromEnvironment();
        putenv('FREESHIP_NUDGE_MAX_GAP');

        $this->assertSame(500000, $policy->threshold);
        $this->assertSame(150000, $policy->nudgeMaxGap);
    }
}
