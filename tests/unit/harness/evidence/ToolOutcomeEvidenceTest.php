<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * build_outfit / freeship_gap end-to-end through the evidence loop: a valid
 * tool result must return a final answer, a failing tool must fall back
 * without fabricating an outcome.
 */
final class ToolOutcomeEvidenceTest extends TestCase
{
    private string|false $previousThreshold = false;

    protected function setUp(): void
    {
        $this->previousThreshold = getenv('FREE_SHIPPING_THRESHOLD');
        putenv('FREE_SHIPPING_THRESHOLD=500000');
    }

    protected function tearDown(): void
    {
        putenv($this->previousThreshold === false ? 'FREE_SHIPPING_THRESHOLD' : 'FREE_SHIPPING_THRESHOLD=' . $this->previousThreshold);
    }

    private function freeshipIntent(): array
    {
        return [
            'primary_intent' => 'freeship_gap', 'entities' => [], 'requested_fields' => [],
            'missing_slots' => [], 'original_query' => 'mua thêm gì để được freeship',
        ];
    }

    private function outfitIntent(): array
    {
        return [
            'primary_intent' => 'build_outfit', 'entities' => ['style_tags' => ['phongvan'], 'max_price' => 800000],
            'requested_fields' => [], 'missing_slots' => [], 'original_query' => 'set đồ đi phỏng vấn 800k',
        ];
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} [loop result, response] */
    private function runTurn(array $intent, StubOutcomeGateway $gateway): array
    {
        $caps = CapabilityRegistry::fromToolDefinitions($gateway->getDefinitions());
        $loop = (new EvidenceExecutionLoop($gateway, $caps))->run((string)$intent['original_query'], $intent, []);
        $response = (new ResponseGenerator())->generate((string)$intent['original_query'], $intent, $loop['normalized'], $loop['plan']);
        return [$loop, $response];
    }

    /** @return array<string,array{0: array<string,mixed>}> */
    public static function freeshipResults(): array
    {
        return [
            'requires_login' => [['status' => 'requires_login', 'threshold' => 500000, 'products' => []]],
            'empty_cart' => [['status' => 'empty_cart', 'threshold' => 500000, 'subtotal' => 0, 'gap' => 500000, 'products' => []]],
            'qualified' => [['status' => 'qualified', 'threshold' => 500000, 'subtotal' => 620000, 'gap' => 0, 'products' => []]],
            'gap' => [[
                'status' => 'gap', 'threshold' => 500000, 'subtotal' => 380000, 'gap' => 120000,
                'products' => [['id' => 90, 'name' => 'Tất Cổ Cao', 'price' => 129000, 'stock' => 8, 'category_id' => 4]],
            ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('freeshipResults')]
    public function testValidFreeshipResultReturnsFinalAnswer(array $result): void
    {
        [$loop, $response] = $this->runTurn($this->freeshipIntent(), new StubOutcomeGateway(['suggest_freeship_items' => $result]));

        $this->assertSame('return', $loop['decision']['action'], json_encode($loop['evidence_score']));
        $this->assertSame('final_answer', $response['response_type']);
        $this->assertSame($result['status'], $response['freeship_status']);
    }

    public function testUnknownFreeshipStatusIsNotEvidence(): void
    {
        [$loop] = $this->runTurn($this->freeshipIntent(), new StubOutcomeGateway([
            'suggest_freeship_items' => ['status' => 'bogus', 'threshold' => 500000, 'products' => []],
        ]));

        $this->assertNotSame('return', $loop['decision']['action']);
        $this->assertContains('freeship_status', $loop['evidence_score']['missing_evidence']);
    }

    public function testValidOutfitResultReturnsFinalAnswer(): void
    {
        [$loop, $response] = $this->runTurn($this->outfitIntent(), new StubOutcomeGateway(['build_outfit' => [
            'status' => 'ok', 'reason' => null, 'style_tags' => ['phongvan'], 'budget' => 800000, 'cheapest_total' => 790000,
            'size_by_category' => [], 'outfits' => [['outfit_index' => 1, 'product_ids' => [61, 66], 'total_price' => 790000, 'matched_tags' => ['phongvan']]],
            'products' => [
                ['id' => 61, 'name' => 'Áo Dài Tay Cổ Tim', 'price' => 250000, 'stock' => 10, 'category_id' => 1, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần Tây Ống Đứng Xám', 'price' => 540000, 'stock' => 11, 'category_id' => 2, 'outfit_index' => 1],
            ],
        ]]));

        $this->assertSame('return', $loop['decision']['action']);
        $this->assertSame('final_answer', $response['response_type']);
    }

    public function testFailingFreeshipToolFallsBackWithoutFalseClaims(): void
    {
        $gateway = new StubOutcomeGateway(['suggest_freeship_items' => new RuntimeException('cart service down')]);
        [$loop, $response] = $this->runTurn($this->freeshipIntent(), $gateway);

        $this->assertSame('fallback', $loop['decision']['action']);
        $this->assertSame(2, $gateway->calls, 'temporary tool error is retried once');
        foreach ($loop['normalized']['evidence'] as $item) {
            $this->assertNotSame('freeship_status', $item['fact_type'] ?? null, 'no fabricated freeship status');
        }
        $this->assertNull($response['freeship_status']);
        $this->assertStringNotContainsString(' 0đ', $response['message']);
        $this->assertStringContainsString('500.000đ', $response['message']);
    }

    public function testFailingOutfitToolFallsBackWithoutFalseClaims(): void
    {
        $gateway = new StubOutcomeGateway(['build_outfit' => new RuntimeException('catalog down')]);
        [$loop, $response] = $this->runTurn($this->outfitIntent(), $gateway);

        $this->assertSame('fallback', $loop['decision']['action']);
        $this->assertSame(2, $gateway->calls, 'temporary tool error is retried once');
        foreach ($loop['normalized']['evidence'] as $item) {
            $this->assertNotContains($item['fact_type'] ?? null, ['outfit_sets', 'outfit_unavailable'], 'no fabricated outfit outcome');
        }
        $this->assertStringNotContainsString('chưa đủ sản phẩm', $response['message']);
        $this->assertStringContainsString('tạm thời', $response['message']);
    }

    public function testNormalizerRecordsNoOutcomeForErrorResults(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize([], ['results' => [
            'outfit' => ['tool' => 'build_outfit', 'success' => false, 'result' => ['error' => 'boom']],
            'freeship' => ['tool' => 'suggest_freeship_items', 'success' => false, 'result' => ['error' => 'boom']],
        ]]);

        $this->assertSame([], $normalized['evidence']);
        $this->assertSame([], $normalized['outfits']);
    }
}
