<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PolicyIntentKeywordTest extends TestCase
{
    private function intentOf(string $message): string
    {
        return (string)((new DeterministicIntentParser())->parse($message, [])->toArray()['resolved_fields']['intent']['value'] ?? '');
    }

    public function testCodeSubstringDoesNotTriggerPolicy(): void
    {
        $this->assertNotSame('policy', $this->intentOf('viết code python sắp xếp mảng'));
    }

    public function testCodPaymentStillTriggersPolicy(): void
    {
        $this->assertSame('policy', $this->intentOf('shop có hỗ trợ COD không'));
    }
}
