<?php

final class StreamingResponseGeneratorTest extends \PHPUnit\Framework\TestCase
{
    public function testForwardsNativeProviderDeltasWithoutSyntheticChunking(): void
    {
        $provider = new class implements StreamingLLMProvider {
            public array $messages = [];

            public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
            {
                $this->messages = $messages;
                $onDelta('Xin chào ');
                $onDelta('bạn.');
                return new LLMResponse('Xin chào bạn.');
            }
        };
        $deltas = [];
        $answer = (new StreamingResponseGenerator())->stream(
            $provider,
            'Tìm áo thun',
            [
                'message' => 'Mình tìm thấy 1 sản phẩm áo thun.',
                'primary_intent' => 'product_search',
                'products' => [['id' => 50, 'name' => 'Áo thun trắng', 'price' => 200000, 'provider_id' => 'secret']],
            ],
            static function (string $delta) use (&$deltas): void { $deltas[] = $delta; }
        );

        self::assertSame(['Xin chào ', 'bạn.'], $deltas);
        self::assertSame('Xin chào bạn.', $answer);
        self::assertStringContainsString('grounded_draft', (string)($provider->messages[1]['content'] ?? ''));
        self::assertStringNotContainsString('secret', (string)($provider->messages[1]['content'] ?? ''));
    }

    public function testEmptyNativeStreamIsRejected(): void
    {
        $provider = new class implements StreamingLLMProvider {
            public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
            {
                return new LLMResponse('');
            }
        };

        $this->expectException(\RuntimeException::class);
        (new StreamingResponseGenerator())->stream($provider, 'Xin chào', ['message' => 'draft'], static function (): void {});
    }
}
