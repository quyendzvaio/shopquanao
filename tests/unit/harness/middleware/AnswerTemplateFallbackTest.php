<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AnswerTemplateFallbackTest extends TestCase
{
    private const DRAFT = 'Set 1: Áo sơ mi SP53 + Quần âu SP61, tổng 665.000đ.';

    /** @param list<string> $chunks streamed before $error (if any) is thrown */
    private function llm(array $chunks, ?Throwable $error = null): StreamingLLMProvider
    {
        return new class ($chunks, $error) implements StreamingLLMProvider {
            public function __construct(private array $chunks, private ?Throwable $error) {}

            public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
            {
                foreach ($this->chunks as $chunk) $onDelta($chunk);
                if ($this->error !== null) throw $this->error;
                return new LLMResponse(implode('', $this->chunks));
            }
        };
    }

    /** @return array{0: array{text: string, fallback: ?string}, 1: list<string>} */
    private function generate(StreamingLLMProvider $llm, string $draft = self::DRAFT): array
    {
        $fake = new class ($llm) implements LLMProvider, StreamingLLMProvider {
            public function __construct(private StreamingLLMProvider $stream) {}

            public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
            {
                throw new LogicException('chat() is not used by the answer stream');
            }

            public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
            {
                return $this->stream->chatStream($messages, $onDelta, $options);
            }
        };
        $deltas = [];
        $result = (new StreamingResponseGenerator())->stream(
            new MiddlewareModel($fake, new MiddlewareStack([new AnswerTemplateFallback()])),
            'cần đồ đi phỏng vấn',
            ['message' => $draft, 'primary_intent' => 'build_outfit', 'products' => []],
            static function (string $delta) use (&$deltas): void { $deltas[] = $delta; }
        );
        return [$result, $deltas];
    }

    public function testStreamsTheLlmRewriteWhenTheProviderSucceeds(): void
    {
        [$result, $deltas] = $this->generate($this->llm(['Bạn có thể ', 'chọn set 1.']));

        $this->assertSame('Bạn có thể chọn set 1.', $result['text']);
        $this->assertNull($result['fallback']);
        $this->assertSame(['Bạn có thể ', 'chọn set 1.'], $deltas);
    }

    public function testFallsBackToTheGroundedDraftWhenTheProviderFailsBeforeAnyToken(): void
    {
        [$result, $deltas] = $this->generate($this->llm([], new LLMTransportException('timeout', 'LLM request failed')));

        $this->assertSame(self::DRAFT, $result['text']);
        $this->assertSame('timeout', $result['fallback']);
        $this->assertSame([self::DRAFT], $deltas);
    }

    public function testAppendsTheGroundedDraftWhenTheStreamBreaksMidAnswer(): void
    {
        [$result, $deltas] = $this->generate($this->llm(['Bạn có thể '], new LLMTransportException('provider_unavailable', 'stream cut')));

        $this->assertSame('provider_unavailable', $result['fallback']);
        $this->assertSame(['Bạn có thể ', "\n\n" . self::DRAFT], $deltas);
        $this->assertSame(implode('', $deltas), $result['text']);
    }

    public function testFallsBackToTheGroundedDraftWhenTheProviderReturnsNoText(): void
    {
        [$result, $deltas] = $this->generate($this->llm(['   ']));

        $this->assertSame('empty_answer', $result['fallback']);
        $this->assertSame(self::DRAFT, $result['text']);
        $this->assertSame(['   ', self::DRAFT], $deltas);
    }

    public function testNonTransportFailuresAlsoFallBack(): void
    {
        [$result] = $this->generate($this->llm([], new RuntimeException('boom')));

        $this->assertSame(self::DRAFT, $result['text']);
        $this->assertSame('runtime_failure', $result['fallback']);
    }

    public function testRethrowsWhenThereIsNoDraftToFallBackTo(): void
    {
        $this->expectException(LLMTransportException::class);
        $this->generate($this->llm([], new LLMTransportException('timeout', 'LLM request failed')), '');
    }

    public function testRequiredFallbackCannotBeRemovedFromConfig(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'harness');
        file_put_contents($config, "<?php\nreturn ['middleware' => []];\n");
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Required middleware missing: AnswerTemplateFallback');
            (new AgentFactory(new HarnessConfig(), $config))->middlewareStack(SqliteShop::pdo(), 1);
        } finally {
            unlink($config);
        }
    }
}
