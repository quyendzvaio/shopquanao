<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingIntentTest extends TestCase
{
    private function intentOf(string $message): string
    {
        return (string)((new DeterministicIntentParser())->parse($message, [])->toArray()['resolved_fields']['intent']['value'] ?? '');
    }

    public function testGapQuestionsAreFreeshipGap(): void
    {
        foreach ([
            'mua thêm gì để được free ship',
            'còn thiếu bao nhiêu để freeship',
            'mua thêm bao nhiêu để được miễn phí ship',
        ] as $message) {
            $this->assertSame('freeship_gap', $this->intentOf($message), $message);
        }
    }

    public function testPolicyQuestionAndCheckoutAreNotGapQuestions(): void
    {
        $this->assertSame('shipping', $this->intentOf('đơn bao nhiêu thì được freeship'));
        $this->assertSame('unsupported_checkout', $this->intentOf('thêm món rẻ nhất vào giỏ để được freeship'));
    }

    public function testPlannerAndAnswerForAGap(): void
    {
        $plan = (new ToolPlanner([]))->plan(['primary_intent' => 'freeship_gap', 'entities' => [], 'missing_slots' => []]);
        $this->assertSame(['tool' => 'suggest_freeship_items', 'args' => ['limit' => 3], 'id' => 'freeship'], $plan['batches'][0][0]);

        $intent = ['primary_intent' => 'freeship_gap', 'entities' => []];
        $normalized = (new EvidenceNormalizer())->normalize($intent, ['results' => ['freeship' => [
            'tool' => 'suggest_freeship_items', 'success' => true,
            'result' => ['status' => 'gap', 'threshold' => 500000, 'subtotal' => 415000, 'gap' => 85000,
                'products' => [['id' => 58, 'name' => 'Áo Thun Graphic Phối Màu', 'price' => 210000, 'stock' => 15]]],
        ]]]);
        $response = (new ResponseGenerator())->generate('mua thêm gì để free ship', $intent, $normalized, ['response_type' => 'final_answer']);

        $this->assertStringContainsString('còn thiếu 85.000đ', $response['message']);
        $this->assertStringContainsString('Áo Thun Graphic Phối Màu (mã 58)', $response['message']);
        $this->assertSame('gap', $response['freeship_status']);
        $this->assertSame([58], array_column($response['cards'], 'id'));
    }

    public function testAnswerForGuestsAndQualifiedCarts(): void
    {
        $intent = ['primary_intent' => 'freeship_gap', 'entities' => []];
        foreach ([
            'requires_login' => 'đăng nhập',
            'qualified' => 'đã đạt mức miễn phí ship',
            'empty_cart' => 'đang trống',
        ] as $status => $phrase) {
            $normalized = (new EvidenceNormalizer())->normalize($intent, ['results' => ['freeship' => [
                'tool' => 'suggest_freeship_items', 'success' => true,
                'result' => ['status' => $status, 'threshold' => 500000, 'subtotal' => 600000, 'gap' => 0, 'products' => []],
            ]]]);
            $message = (new ResponseGenerator())->generate('free ship', $intent, $normalized, ['response_type' => 'final_answer'])['message'];
            $this->assertStringContainsString($phrase, $message, $status);
        }
    }
}
