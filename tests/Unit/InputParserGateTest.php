<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The full-LLM input re-parse must only run when the deterministic parse is
 * insufficient (unknown/low-confidence intent or missing product type).
 * Confident searches skip it: residual spans belong to the bounded L1
 * enricher, everything else is already sufficient for L0.
 */
final class InputParserGateTest extends TestCase
{
    private function resolve(string $message): array
    {
        return (new IntentResolver(null))->resolve($message, [], []);
    }

    public function testConfidentColorSearchSkipsInputParser(): void
    {
        $result = $this->resolve('tìm áo màu đen');
        $this->assertArrayNotHasKey('llm_input_parse_ms', $result['timings']);
        $this->assertSame('product_search', $result['intent']['primary_intent'] ?? null);
    }

    public function testExplicitDetailSkipsInputParser(): void
    {
        $result = $this->resolve('cho tôi xem chi tiết sản phẩm mã 52');
        $this->assertArrayNotHasKey('llm_input_parse_ms', $result['timings']);
        $this->assertSame('product_detail', $result['intent']['primary_intent'] ?? null);
    }

    public function testMissingProductTypeStillUsesInputParser(): void
    {
        $result = $this->resolve('tìm cái mặc đi biển');
        $this->assertArrayHasKey('llm_input_parse_ms', $result['timings']);
    }

    public function testUnknownIntentStillUsesInputParser(): void
    {
        $result = $this->resolve('abcxyz nội dung không liên quan');
        $this->assertSame('unknown', $result['intent']['primary_intent'] ?? null);
        $this->assertArrayHasKey('llm_input_parse_ms', $result['timings']);
    }

    public function testLienQuanDoesNotResolveToTrousers(): void
    {
        $partial = (new DeterministicIntentParser())
            ->parse('abcxyz nội dung không liên quan', [])
            ->toArray();
        $this->assertSame('unknown', (string)($partial['resolved_fields']['intent']['value'] ?? ''));
    }
}
