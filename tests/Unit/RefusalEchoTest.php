<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Refusal and fallback answers must restate the user's request so the reply
 * stays visibly tied to the question (answer relevancy on noise cases).
 * Quoting user input adds no new factual claim.
 */
final class RefusalEchoTest extends TestCase
{
    private ResponseGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ResponseGenerator();
    }

    private function intent(string $primary): array
    {
        return [
            'primary_intent' => $primary,
            'entities' => [],
            'requested_fields' => [],
            'missing_slots' => [],
            'secondary_intents' => [],
        ];
    }

    public function testOutfitRefusalEchoesQuestion(): void
    {
        $result = $this->generator->generate(
            'Áo thun trắng phối với quần gì cho đẹp?',
            $this->intent('unsupported_outfit'),
            ['cards' => [], 'evidence' => []],
            ['batches' => [], 'response_type' => 'final_answer']
        );
        $answer = (string)($result['answer'] ?? '');
        $this->assertStringContainsString('Áo thun trắng phối với quần gì cho đẹp?', $answer);
        $this->assertStringContainsString('không hỗ trợ', $answer);
    }

    public function testCheckoutRefusalEchoesQuestion(): void
    {
        $result = $this->generator->generate(
            'chốt đơn áo mã 52 cho tôi',
            $this->intent('unsupported_checkout'),
            ['cards' => [], 'evidence' => []],
            ['batches' => [], 'response_type' => 'final_answer']
        );
        $answer = (string)($result['answer'] ?? '');
        $this->assertStringContainsString('chốt đơn áo mã 52 cho tôi', $answer);
        $this->assertStringContainsString('thêm giỏ hàng', $answer);
    }

    public function testUnknownFallbackEchoesQuestion(): void
    {
        $result = $this->generator->generate(
            'abcxyz nội dung không liên quan',
            $this->intent('unknown'),
            ['cards' => [], 'evidence' => []],
            ['batches' => [], 'response_type' => 'fallback']
        );
        $answer = (string)($result['answer'] ?? '');
        $this->assertStringContainsString('abcxyz nội dung không liên quan', $answer);
    }

    public function testEchoIsTruncatedForLongQuestions(): void
    {
        $long = str_repeat('áo đẹp giá rẻ ', 30);
        $result = $this->generator->generate(
            $long,
            $this->intent('unknown'),
            ['cards' => [], 'evidence' => []],
            ['batches' => [], 'response_type' => 'fallback']
        );
        $answer = (string)($result['answer'] ?? '');
        // Quote is capped: a 420-char question must not bloat the answer.
        $this->assertLessThan(mb_strlen($long), mb_strlen($answer));
        $this->assertStringContainsString(mb_substr($long, 0, 60), $answer);
    }
}
