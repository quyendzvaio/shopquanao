<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The streamed turn with no LLM configured and CHATBOT_TEMPLATE_RESPONSE off:
 * the required AnswerTemplateFallback middleware must still stream the grounded
 * draft (an LLM outage or absence never fails a turn).
 */
final class StreamWithoutLlmTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        $this->pdo->exec('ALTER TABLE products ADD COLUMN subcategory_id INTEGER');
        $this->pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, canonical_key TEXT)');
        $this->pdo->exec('CREATE TABLE product_subcategories (id INTEGER PRIMARY KEY, canonical_key TEXT, display_name TEXT)');
        $this->pdo->exec("INSERT INTO categories (id, name, canonical_key) VALUES (1, 'Áo', 'ao')");
        SqliteShop::product($this->pdo, 53, 1, 'Áo Len Cổ Tròn Xám', 415000, 8, 'congso,phongvan,mualanh');
    }

    private function factory(): AgentFactory
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'harness');
        file_put_contents($path, "<?php\nreturn ['middleware' => [CheckoutGuardrail::class, AnswerTemplateFallback::class]];\n");
        register_shutdown_function(static fn () => @unlink($path));

        return new AgentFactory(new HarnessConfig(toolTransport: 'internal', templateResponse: false, llm: null, streamLlm: null), $path);
    }

    /** @return array{0:array<string,mixed>,1:list<string>} */
    private function stream(string $message, array $overrides = []): array
    {
        $deltas = [];
        $runner = $this->factory()->turnRunner($this->pdo, 9, null, $overrides + [
            'memory' => new class implements ChatbotMemoryStore {
                public function ensureSchema(): void
                {
                }

                public function rememberUserMessage(string $message): array
                {
                    return ['slots' => []];
                }

                public function refreshSummary(): void
                {
                }
            },
            'conversation_store' => new class implements ChatbotConversationStore {
                public function findLastProductId(): ?int
                {
                    return null;
                }

                public function saveMessages(string $userMessage, string $botMessage, array $products, array $knowledgeSources, array $evaluationMetadata, array $responseMetadata): void
                {
                }

                public function logToolExecution(string $tool, array $arguments, mixed $result, int $durationMs, bool $success): void
                {
                }
            },
        ]);
        $result = $runner->run(new TurnContext(
            $message,
            9,
            null,
            [],
            static function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            }
        ))->toArray();

        return [$result, $deltas];
    }

    public function testStreamWithoutLlmEmitsTheGroundedDraftAsTemplateFallback(): void
    {
        [$result, $deltas] = $this->stream('tìm áo len');

        $this->assertSame('product_search', $result['primary_intent']);
        $this->assertNotSame([], $deltas, 'the grounded draft must be streamed');
        $this->assertSame($result['message'], implode('', $deltas));
        $this->assertStringContainsString('Áo Len Cổ Tròn Xám', $result['message']);
        $this->assertSame('template_fallback', $result['latency']['streaming']);
        $this->assertSame([53], array_column($result['products'], 'id'));
    }

    public function testGuardrailRefusalStillBlocksBeforeTheAnswerStep(): void
    {
        [$result, $deltas] = $this->stream('đặt hàng giúp mình luôn nhé');

        $this->assertSame('unsupported_checkout', $result['primary_intent']);
        $this->assertSame('CheckoutGuardrail', $result['latency']['blocked_by']);
        $this->assertSame([], $result['latency']['routing']['selected_tools'] ?? []);
        $this->assertSame('template_fallback', $result['latency']['streaming']);
        $this->assertSame($result['message'], implode('', $deltas));
    }

    public function testARealStreamingLlmStillStreamsItsOwnAnswer(): void
    {
        $llm = new class implements LLMProvider, StreamingLLMProvider {
            public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
            {
                throw new LogicException('chat() is not used by the answer stream');
            }

            public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
            {
                $onDelta('Mình gợi ý ');
                $onDelta('Áo Len Cổ Tròn Xám.');
                return new LLMResponse('Mình gợi ý Áo Len Cổ Tròn Xám.', 'stop', [], null);
            }
        };

        [$result, $deltas] = $this->stream('tìm áo len', ['llm' => $llm]);

        $this->assertSame(['Mình gợi ý ', 'Áo Len Cổ Tròn Xám.'], $deltas);
        $this->assertTrue($result['latency']['streaming']);
    }
}
