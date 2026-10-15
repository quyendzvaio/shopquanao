<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitIntentTest extends TestCase
{
    /** @return array<string,mixed> resolved field => value */
    private function fields(string $message): array
    {
        $resolved = (new DeterministicIntentParser())->parse($message, [])->toArray()['resolved_fields'];
        return array_map(fn($meta) => $meta['value'] ?? null, $resolved);
    }

    public function testOccasionRequestWithBudgetAndMeasurements(): void
    {
        $f = $this->fields('Có 800k, cần đồ đi phỏng vấn, cao 1m70 nặng 65kg');
        $this->assertSame('build_outfit', $f['intent']);
        $this->assertSame(['phongvan'], $f['style_tags']);
        $this->assertSame(800000, $f['max_price']);
        $this->assertSame(170, $f['height_cm']);
        $this->assertSame(65, $f['weight_kg']);
    }

    public function testGenericOutfitLanguageBecomesBuildOutfit(): void
    {
        $f = $this->fields('phối giúp tôi một set đồ đi chơi gồm áo và quần');
        $this->assertSame('build_outfit', $f['intent']);
        $this->assertSame(['dichoi'], $f['style_tags']);
    }

    public function testAnchoredStylingKeepsProductId(): void
    {
        $f = $this->fields('Áo mã 61 phối với gì');
        $this->assertSame('build_outfit', $f['intent']);
        $this->assertSame(61, $f['product_id']);
    }

    public function testBudgetKeywordsNeedAMoneyUnit(): void
    {
        $this->assertSame(1000000, $this->fields('đi biển nên mặc gì, ngân sách 1 triệu')['max_price']);
        $this->assertSame(1500000, $this->fields('trời lạnh mặc gì đi làm, có 1.5 triệu')['max_price']);
        $this->assertArrayNotHasKey('max_price', $this->fields('có 2 cái áo thun trắng không'));
    }

    public function testCheckoutStillWinsOverOutfitLanguage(): void
    {
        $this->assertSame('unsupported_checkout', $this->fields('đặt hàng giúp mình set đồ đi chơi')['intent']);
    }

    public function testOccasionWithoutWearLanguageIsNotAnOutfit(): void
    {
        $this->assertNotSame('build_outfit', $this->fields('chính sách đổi trả hàng đi làm')['intent']);
    }

    public function testAccentedWordsAreNotSizes(): void
    {
        $this->assertArrayNotHasKey('size', $this->fields('trời lạnh mặc gì đi làm'));
        $this->assertSame('M', $this->fields('đi hẹn hò mặc gì size M')['size']);
        $this->assertSame('L', $this->fields('áo thun size l còn không')['size']);
    }

    public function testOutfitWithoutOccasionAsksForIt(): void
    {
        $partial = (new DeterministicIntentParser())->parse('phối đồ giúp mình', [])->toArray();
        $this->assertContains('style_tags', $partial['missing_fields']);
    }

    public function testBareCoAmountIsNotABudgetOutsideOutfitRequests(): void
    {
        $this->assertArrayNotHasKey('max_price', $this->fields('áo thun có 300k không'));
        $this->assertArrayNotHasKey('max_price', $this->fields('shop có 200k áo polo'));
        $this->assertSame(1000000, $this->fields('ngân sách 1 triệu cho áo thun')['max_price']);
    }

    public function testExplicitCeilingWinsOverOutfitBudget(): void
    {
        // Candidates are ordered range, max keywords, min, then outfit budget;
        // the first max_price wins, so an explicit "dưới" ceiling is kept.
        $f = $this->fields('cần đồ đi làm dưới 500k, có 800k');
        $this->assertSame(500000, $f['max_price']);
    }
}
