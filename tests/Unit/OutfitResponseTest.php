<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitResponseTest extends TestCase
{
    private function toolResult(array $outfits, array $products, ?string $reason = null, ?int $cheapest = null): array
    {
        return ['results' => ['outfit' => ['tool' => 'build_outfit', 'success' => true, 'result' => [
            'status' => $outfits === [] ? 'no_outfit' : 'ok', 'reason' => $reason, 'style_tags' => ['phongvan'],
            'budget' => 800000, 'cheapest_total' => $cheapest, 'outfits' => $outfits, 'products' => $products,
        ]]]];
    }

    private function intent(): array
    {
        return ['primary_intent' => 'build_outfit', 'entities' => ['style_tags' => ['phongvan'], 'max_price' => 800000]];
    }

    public function testAnswerListsEachSetWithTotalsAndKeepsOutfitIndexOnCards(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult(
            [['outfit_index' => 1, 'product_ids' => [61, 66], 'total_price' => 790000, 'matched_tags' => ['phongvan']]],
            [
                ['id' => 61, 'name' => 'Áo Dài Tay Cổ Tim', 'price' => 250000, 'stock' => 10, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần Tây Ống Đứng Xám', 'price' => 540000, 'stock' => 11, 'outfit_index' => 1],
            ]
        ));
        $normalized = (new ProductConstraintVerifier())->verify($this->intent(), $normalized);
        $response = (new ResponseGenerator())->generate('800k đi phỏng vấn', $this->intent(), $normalized, ['response_type' => 'final_answer']);

        $this->assertStringContainsString('Set 1, tổng 790.000đ', $response['message']);
        $this->assertStringContainsString('phỏng vấn', $response['message']);
        $this->assertSame(1, $response['cards'][0]['outfit_index']);
        $this->assertSame([61, 66], $response['outfits'][0]['product_ids']);
    }

    public function testEachSizedItemListsItsRecommendedSize(): void
    {
        $result = $this->toolResult(
            [['outfit_index' => 1, 'product_ids' => [61, 66, 90], 'total_price' => 780000, 'matched_tags' => ['phongvan']]],
            [
                ['id' => 61, 'name' => 'Áo Dài Tay Cổ Tim', 'price' => 250000, 'stock' => 10, 'category_id' => 1, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần Tây Ống Đứng Xám', 'price' => 400000, 'stock' => 11, 'category_id' => 2, 'outfit_index' => 1],
                ['id' => 90, 'name' => 'Thắt Lưng Da', 'price' => 130000, 'stock' => 5, 'category_id' => 4, 'outfit_index' => 1],
            ]
        );
        $result['results']['outfit']['result']['size_by_category'] = [1 => 'M', 2 => 'M', 3 => 'L'];
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $result);
        $normalized = (new ProductConstraintVerifier())->verify($this->intent(), $normalized);
        $response = (new ResponseGenerator())->generate('800k đi phỏng vấn', $this->intent(), $normalized, ['response_type' => 'final_answer']);

        $this->assertStringContainsString('Áo Dài Tay Cổ Tim (mã 61) 250.000đ, size M', $response['message']);
        $this->assertStringContainsString('Quần Tây Ống Đứng Xám (mã 66) 400.000đ, size M', $response['message']);
        $this->assertStringContainsString('Thắt Lưng Da (mã 90) 130.000đ. ', $response['message']);
        $this->assertStringNotContainsString('130.000đ, size', $response['message']);
    }

    public function testNoKnownSizeRendersItemsWithoutSize(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult(
            [['outfit_index' => 1, 'product_ids' => [61, 66], 'total_price' => 790000, 'matched_tags' => ['phongvan']]],
            [
                ['id' => 61, 'name' => 'Áo Dài Tay Cổ Tim', 'price' => 250000, 'stock' => 10, 'category_id' => 1, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần Tây Ống Đứng Xám', 'price' => 540000, 'stock' => 11, 'category_id' => 2, 'outfit_index' => 1],
            ]
        ));
        $normalized = (new ProductConstraintVerifier())->verify($this->intent(), $normalized);
        $response = (new ResponseGenerator())->generate('800k', $this->intent(), $normalized, ['response_type' => 'final_answer']);

        $this->assertStringNotContainsString('size', $response['message']);
    }

    public function testVerifierDropsSetsOverBudgetOrOutOfStock(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult(
            [['outfit_index' => 1, 'product_ids' => [57, 66], 'total_price' => 1430000, 'matched_tags' => ['phongvan']]],
            [
                ['id' => 57, 'name' => 'Áo Vest', 'price' => 890000, 'stock' => 2, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần Tây', 'price' => 540000, 'stock' => 11, 'outfit_index' => 1],
            ]
        ));
        $verified = (new ProductConstraintVerifier())->verify($this->intent(), $normalized);

        $this->assertSame([], $verified['outfits']);
        $this->assertSame([], $verified['cards']);
    }

    public function testBudgetTooLowAnswerQuotesCheapestSet(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult([], [], 'budget_too_low', 680000));
        $response = (new ResponseGenerator())->generate('300k', $this->intent(), $normalized, ['response_type' => 'final_answer']);

        $this->assertStringContainsString('680.000đ', $response['message']);
        $this->assertSame([], $response['cards']);
    }

    public function testClarificationListsSupportedOccasions(): void
    {
        $intent = ['primary_intent' => 'build_outfit', 'entities' => [], 'missing_slots' => []];
        $response = (new ResponseGenerator())->generate('phối đồ giúp mình', $intent, ['cards' => [], 'evidence' => []], ['response_type' => 'clarification']);

        $this->assertSame('clarification', $response['response_type']);
        $this->assertStringContainsString('phỏng vấn', $response['message']);
    }

    public function testPartialDropRenumbersSetsAndCards(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult(
            [
                ['outfit_index' => 1, 'product_ids' => [57, 66], 'total_price' => 1430000, 'matched_tags' => ['phongvan']],
                ['outfit_index' => 2, 'product_ids' => [61, 82], 'total_price' => 720000, 'matched_tags' => ['phongvan']],
            ],
            [
                ['id' => 57, 'name' => 'Áo Vest', 'price' => 890000, 'stock' => 2, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần Tây', 'price' => 540000, 'stock' => 11, 'outfit_index' => 1],
                ['id' => 61, 'name' => 'Áo Dài Tay', 'price' => 250000, 'stock' => 10, 'outfit_index' => 2],
                ['id' => 82, 'name' => 'Chân Váy', 'price' => 470000, 'stock' => 5, 'outfit_index' => 2],
            ]
        ));
        $normalized = (new ProductConstraintVerifier())->verify($this->intent(), $normalized);
        $response = (new ResponseGenerator())->generate('800k', $this->intent(), $normalized, ['response_type' => 'final_answer']);

        $this->assertStringContainsString('Set 1, tổng 720.000đ', $response['message']);
        $this->assertSame(1, $response['outfits'][0]['outfit_index']);
        $this->assertSame([1, 1], array_column($response['cards'], 'outfit_index'));
    }

    public function testVerifiedTotalOverridesToolTotal(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult(
            [['outfit_index' => 1, 'product_ids' => [61, 66], 'total_price' => 100000, 'matched_tags' => ['phongvan']]],
            [
                ['id' => 61, 'name' => 'Áo', 'price' => 250000, 'stock' => 10, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần', 'price' => 540000, 'stock' => 11, 'outfit_index' => 1],
            ]
        ));
        $normalized = (new ProductConstraintVerifier())->verify($this->intent(), $normalized);
        $response = (new ResponseGenerator())->generate('800k', $this->intent(), $normalized, ['response_type' => 'final_answer']);

        $this->assertSame(790000, $response['outfits'][0]['total_price']);
        $this->assertStringContainsString('Set 1, tổng 790.000đ', $response['message']);
    }
}
