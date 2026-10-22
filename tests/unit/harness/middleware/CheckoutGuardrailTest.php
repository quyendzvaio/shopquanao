<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutGuardrailTest extends TestCase
{
    private function turn(string $message): TurnContext
    {
        return new TurnContext($message, 1, null, [], null);
    }

    public function testBlocksCheckoutWithRefusal(): void
    {
        $message = 'chốt đơn áo mã 52 cho tôi';

        $result = (new CheckoutGuardrail(new ResponseGenerator()))->beforeTurn($this->turn($message));

        $this->assertNotNull($result);
        $refusal = (new ResponseGenerator())->checkoutRefusal($message);
        $this->assertSame($refusal, $result->get('message'));
        $this->assertSame($refusal, $result->get('answer'));
        $this->assertSame('unsupported_checkout', $result->get('primary_intent'));
        $this->assertSame('final_answer', $result->get('response_type'));
        $this->assertSame([], $result->get('products'));
        $this->assertSame([], $result->get('cards'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string)$result->get('trace_id'));
        $this->assertSame(
            ['pipeline' => 'deterministic_hybrid_pipeline', 'blocked_by' => 'CheckoutGuardrail', 'total_ms' => 0],
            $result->get('latency')
        );
    }

    public function testRefusalIsTheTextOfThePipelineCheckoutBranch(): void
    {
        $message = 'thêm áo mã 52 size M vào giỏ giúp tôi';
        $generator = new ResponseGenerator();

        $pipeline = $generator->generate(
            $message,
            ['primary_intent' => 'unsupported_checkout', 'entities' => []],
            ['cards' => [], 'evidence' => []],
            ['batches' => [], 'response_type' => 'final_answer']
        );

        $this->assertSame($pipeline['message'], $generator->checkoutRefusal($message));
    }

    public function testPassesThroughOtherMessages(): void
    {
        $guardrail = new CheckoutGuardrail(new ResponseGenerator());

        $this->assertNull($guardrail->beforeTurn($this->turn('áo thun dưới 300k')));
        $this->assertNull($guardrail->beforeTurn($this->turn('chính sách thanh toán')));
    }

    public function testGuardrailCannotBeRemovedFromConfig(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'harness');
        file_put_contents($config, "<?php\nreturn ['middleware' => [AnswerTemplateFallback::class]];\n");
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Required middleware missing: CheckoutGuardrail');
            (new AgentFactory(new HarnessConfig(), $config))->middlewareStack(SqliteShop::pdo(), 1);
        } finally {
            unlink($config);
        }
    }

    public function testBlockedTurnRunsNoToolsAndKeepsItsRoutingRecord(): void
    {
        $store = $this->recordingStore();
        $service = new ChatbotService(SqliteShop::pdo(), 1, null, null, $this->failingGateway(), $this->memory(), $store);

        $result = $service->respond('chốt đơn áo mã 52 cho tôi');

        $this->assertSame('unsupported_checkout', $result['primary_intent']);
        $this->assertSame((new ResponseGenerator())->checkoutRefusal('chốt đơn áo mã 52 cho tôi'), $result['message']);
        $this->assertSame([['routing_pipeline', ['blocked_by' => 'CheckoutGuardrail', 'selected_tools' => []]]], $store->logs);
    }

    public function testBlockedStreamingTurnRewritesTheRefusalAsTheGroundedDraft(): void
    {
        $store = $this->recordingStore();
        $llm = new class implements LLMProvider, StreamingLLMProvider {
            public string $payload = '';

            public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
            {
                throw new LogicException('chat() is not used on a blocked turn');
            }

            public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
            {
                $this->payload = (string)end($messages)['content'];
                $onDelta('Mình không thể chốt đơn giúp bạn.');
                return new LLMResponse('Mình không thể chốt đơn giúp bạn.');
            }
        };
        $service = new ChatbotService(SqliteShop::pdo(), 1, null, $llm, $this->failingGateway(), $this->memory(), $store);
        $deltas = [];

        $result = $service->respondStreaming('chốt đơn áo mã 52 cho tôi', static function (string $delta) use (&$deltas): void {
            $deltas[] = $delta;
        });

        $draft = (new ResponseGenerator())->checkoutRefusal('chốt đơn áo mã 52 cho tôi');
        $this->assertSame($draft, json_decode($llm->payload, true)['grounded_draft']);
        $this->assertSame(['Mình không thể chốt đơn giúp bạn.'], $deltas);
        $this->assertSame('Mình không thể chốt đơn giúp bạn.', $result['message']);
        $this->assertSame('unsupported_checkout', $result['primary_intent']);
        $this->assertSame([['routing_pipeline', ['blocked_by' => 'CheckoutGuardrail', 'selected_tools' => []]]], $store->logs);
    }

    private function failingGateway(): ChatbotToolGateway
    {
        return new class implements ChatbotToolGateway {
            public function getDefinitions(): array
            {
                throw new LogicException('a blocked turn must not reach the tools');
            }

            public function execute(string $toolName, array $arguments): array
            {
                throw new LogicException('a blocked turn must not execute ' . $toolName);
            }
        };
    }

    private function memory(): ChatbotMemoryStore
    {
        return new class implements ChatbotMemoryStore {
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
        };
    }

    private function recordingStore(): ChatbotConversationStore
    {
        return new class implements ChatbotConversationStore {
            /** @var list<array{0:string,1:mixed}> */
            public array $logs = [];

            public function findLastProductId(): ?int
            {
                return null;
            }

            public function saveMessages(
                string $userMessage,
                string $botMessage,
                array $products,
                array $knowledgeSources,
                array $evaluationMetadata,
                array $responseMetadata
            ): void {
            }

            public function logToolExecution(string $tool, array $arguments, mixed $result, int $durationMs, bool $success): void
            {
                $this->logs[] = [$tool, $result];
            }
        };
    }
}
