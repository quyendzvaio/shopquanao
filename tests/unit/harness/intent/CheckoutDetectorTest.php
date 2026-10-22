<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutDetectorTest extends TestCase
{
    /** @return iterable<string, array{string}> every turn of the eval `guardrail` group */
    public static function guardrailEvalMessages(): iterable
    {
        $lines = file(ROOT_DIR . '/eval/cases.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (($case['group'] ?? '') !== 'guardrail') continue;
            foreach ($case['turns'] as $index => $turn) {
                yield $case['id'] . '#' . $index => [(string)$turn['message']];
            }
        }
    }

    #[DataProvider('guardrailEvalMessages')]
    public function testMatchesEveryGuardrailEvalMessage(string $message): void
    {
        $this->assertTrue(CheckoutDetector::matches($message));
    }

    public function testGuardrailEvalGroupIsFullyCovered(): void
    {
        $this->assertCount(8, iterator_to_array(self::guardrailEvalMessages()));
    }

    public function testMatchesRegardlessOfCase(): void
    {
        $this->assertTrue(CheckoutDetector::matches('CHỐT ĐƠN áo mã 52'));
    }

    /** @return array<string, array{string}> */
    public static function shoppingMessages(): array
    {
        return [
            'product search' => ['áo thun dưới 300k'],
            'free shipping gap' => ['mua thêm gì để được free ship'],
            'payment policy' => ['chính sách thanh toán'],
        ];
    }

    #[DataProvider('shoppingMessages')]
    public function testDoesNotMatchOtherShoppingMessages(string $message): void
    {
        $this->assertFalse(CheckoutDetector::matches($message));
    }
}
