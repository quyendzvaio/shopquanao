<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Generation grounding: product/order answers must restate concrete evidence
 * facts (names, prices, stock, statuses) instead of bare counts, so RAGAS
 * faithfulness and answer relevancy reflect the retrieved evidence.
 * No fact outside cards/evidence may appear in the answer.
 */
final class ResponseGeneratorGroundingTest extends TestCase
{
    private ResponseGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ResponseGenerator();
    }

    private function searchIntent(): array
    {
        return [
            'primary_intent' => 'product_search',
            'entities' => [
                'product_type' => 'áo',
                'color' => 'đen',
                'size' => 'M',
                'max_price' => 600000,
            ],
            'requested_fields' => [],
            'missing_slots' => [],
            'secondary_intents' => [],
        ];
    }

    private function searchNormalized(): array
    {
        $cards = [
            ['id' => 52, 'name' => 'Áo Khoác Bomber Kaki Đen', 'price' => 550000, 'stock' => 12],
            ['id' => 63, 'name' => 'Áo Sơ Mi Caro Đỏ Đen', 'price' => 350000, 'stock' => 1],
        ];
        return [
            'cards' => $cards,
            'evidence' => [
                ['source' => 'product_search', 'fact_type' => 'result_count', 'value' => 2],
            ],
        ];
    }

    public function testProductAnswerEchoesConstraints(): void
    {
        $result = $this->generator->generate(
            'tìm áo size M màu đen còn hàng dưới 600k',
            $this->searchIntent(),
            $this->searchNormalized(),
            ['batches' => [[['tool' => 'search_products']]], 'response_type' => 'final_answer']
        );
        $answer = (string)($result['answer'] ?? '');
        $this->assertStringContainsString('màu đen', mb_strtolower($answer));
        $this->assertStringContainsString('600.000', $answer);
    }

    public function testProductAnswerNamesEvidenceProducts(): void
    {
        $result = $this->generator->generate(
            'tìm áo size M màu đen còn hàng dưới 600k',
            $this->searchIntent(),
            $this->searchNormalized(),
            ['batches' => [[['tool' => 'search_products']]], 'response_type' => 'final_answer']
        );
        $answer = (string)($result['answer'] ?? '');
        $this->assertStringContainsString('Áo Khoác Bomber Kaki Đen', $answer);
        $this->assertStringContainsString('550.000', $answer);
    }

    public function testProductAnswerContainsNoForeignProductIds(): void
    {
        $result = $this->generator->generate(
            'tìm áo size M màu đen còn hàng dưới 600k',
            $this->searchIntent(),
            $this->searchNormalized(),
            ['batches' => [[['tool' => 'search_products']]], 'response_type' => 'final_answer']
        );
        $answer = (string)($result['answer'] ?? '');
        preg_match_all('/mã\s*(\d+)/ui', $answer, $matches);
        foreach ($matches[1] as $id) {
            $this->assertContains((int)$id, [52, 63], "un grounded product id mã {$id}");
        }
    }

    public function testOrderAnswerListsAllOwnedOrders(): void
    {
        $intent = [
            'primary_intent' => 'order_status',
            'entities' => [],
            'requested_fields' => [],
            'missing_slots' => [],
            'secondary_intents' => [],
        ];
        $normalized = [
            'cards' => [],
            'evidence' => [
                ['source' => 'order_service', 'fact_type' => 'order_status', 'order_id' => 3, 'value' => 'Đang giao', 'total_price' => 550000.0],
                ['source' => 'order_service', 'fact_type' => 'order_status', 'order_id' => 4, 'value' => 'Đang giao', 'total_price' => 630000.0],
            ],
        ];
        $result = $this->generator->generate(
            'đơn của tôi đang ở trạng thái nào?',
            $intent,
            $normalized,
            ['batches' => [[['tool' => 'get_order_status']]], 'response_type' => 'final_answer']
        );
        $answer = (string)($result['answer'] ?? '');
        $this->assertStringContainsString('#3', $answer);
        $this->assertStringContainsString('#4', $answer);
        $this->assertStringContainsString('Đang giao', $answer);
        $this->assertStringContainsString('550.000', $answer);
    }

    public function testOrderAnswerWithoutEvidenceStaysEmpty(): void
    {
        $intent = [
            'primary_intent' => 'order_status',
            'entities' => [],
            'requested_fields' => [],
            'missing_slots' => [],
            'secondary_intents' => [],
        ];
        $result = $this->generator->generate(
            'đơn của tôi',
            $intent,
            ['cards' => [], 'evidence' => []],
            ['batches' => [], 'response_type' => 'final_answer']
        );
        $answer = (string)($result['answer'] ?? '');
        $this->assertStringNotContainsString('#', $answer);
    }
}
