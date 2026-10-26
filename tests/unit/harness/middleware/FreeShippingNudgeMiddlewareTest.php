<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingNudgeMiddlewareTest extends TestCase
{
    private const NUDGE = 'Giỏ hàng của bạn còn thiếu 85.000đ để được miễn phí ship (đơn từ 500.000đ); '
        . 'bạn có thể tham khảo thêm Áo Thun Graphic Phối Màu (mã 58) giá 210.000đ.';
    private const DRAFT = 'Mình tìm thấy Áo Len Cổ Tròn Xám (mã 53) giá 415.000đ.';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        SqliteShop::product($this->pdo, 53, 1, 'Áo Len Cổ Tròn Xám', 415000, 8, 'congso,phongvan,mualanh');
        SqliteShop::product($this->pdo, 58, 1, 'Áo Thun Graphic Phối Màu', 210000, 15, 'streetstyle,dichoi');
        $this->pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (1, 53, 1, 'M')");
    }

    private function middleware(): FreeShippingNudge
    {
        return new FreeShippingNudge(new ChatMemoryRepository($this->pdo), 9, $policy = new FreeShippingPolicy(500000, 200000), new FreeShippingService($this->pdo, $policy));
    }

    /** @param list<string> $deltas */
    private function turn(?int $userId = 1, ?array &$deltas = null): TurnContext
    {
        $emit = $deltas === null ? null : static function (string $delta) use (&$deltas): void { $deltas[] = $delta; };
        return new TurnContext('cho mình xem áo len', 9, $userId, [], $emit);
    }

    private function turnResult(string $intent = 'product_search', string $message = self::DRAFT): TurnResult
    {
        $card = ['id' => 53, 'name' => 'Áo Len Cổ Tròn Xám', 'price' => 415000];
        return TurnResult::fromArray([
            'message' => $message,
            'answer' => $message,
            'products' => [$card],
            'cards' => [$card],
            'response_type' => 'final_answer',
            'primary_intent' => $intent,
        ]);
    }

    public function testAppendsNudgeAndCardOnce(): void
    {
        $middleware = $this->middleware();

        $first = $middleware->afterTurn($this->turn(), $this->turnResult())->toArray();

        $this->assertTrue($first['freeship_nudge']);
        $this->assertSame(self::DRAFT . ' ' . self::NUDGE, $first['message']);
        $this->assertSame($first['message'], $first['answer']);
        $this->assertSame([53, 58], array_column($first['products'], 'id'));
        $this->assertSame($first['products'], $first['cards']);

        $second = $middleware->afterTurn($this->turn(), $this->turnResult())->toArray();

        $this->assertFalse($second['freeship_nudge'], 'the same cart is nudged once per session');
        $this->assertSame(self::DRAFT, $second['message']);
        $this->assertSame([53], array_column($second['products'], 'id'));
    }

    public function testNudgeCardIsNotDuplicatedWhenTheAnswerAlreadyShowsIt(): void
    {
        $card = ['id' => 58, 'name' => 'Áo Thun Graphic Phối Màu', 'price' => 210000];
        $result = $this->turnResult()->with('products', [$card])->with('cards', [$card]);

        $out = $this->middleware()->afterTurn($this->turn(), $result)->toArray();

        $this->assertTrue($out['freeship_nudge']);
        $this->assertSame([$card], $out['products']);
        $this->assertSame([$card], $out['cards']);
    }

    public function testGuestGetsNoNudge(): void
    {
        $deltas = [];
        $out = $this->middleware()->afterTurn($this->turn(null, $deltas), $this->turnResult())->toArray();

        $this->assertFalse($out['freeship_nudge']);
        $this->assertSame(self::DRAFT, $out['message']);
        $this->assertSame([], $deltas);
        $this->assertNull($this->middleware()->lastFingerprint(), 'a guest turn records nothing');
    }

    public function testNonShoppingIntentIsIgnored(): void
    {
        $out = $this->middleware()->afterTurn($this->turn(), $this->turnResult('return_exchange'))->toArray();

        $this->assertFalse($out['freeship_nudge']);
        $this->assertSame(self::DRAFT, $out['message']);
        $this->assertSame([53], array_column($out['products'], 'id'));
        $this->assertNull($this->middleware()->lastFingerprint());
    }

    public function testNonStreamedTurnTrimsTheAnswerBeforeTheNudge(): void
    {
        $out = $this->middleware()->afterTurn($this->turn(), $this->turnResult(message: self::DRAFT . "  \n"))->toArray();

        $this->assertSame(self::DRAFT . ' ' . self::NUDGE, $out['message']);
    }

    public function testInternalFailureIsSuppressedAndLogged(): void
    {
        $this->pdo->exec('DROP TABLE chat_session_memory');
        $log = tempnam(sys_get_temp_dir(), 'nudge');
        $previous = ini_set('error_log', $log);
        try {
            $out = $this->middleware()->afterTurn($this->turn(), $this->turnResult())->toArray();
        } finally {
            ini_set('error_log', (string)$previous);
        }
        $logged = (string)file_get_contents($log);
        unlink($log);

        $this->assertFalse($out['freeship_nudge']);
        $this->assertSame(self::DRAFT, $out['message']);
        $this->assertStringContainsString(
            '{"operation":"freeship_nudge","success":false,"error_category":"suppressed_runtime_failure"}',
            $logged
        );
    }

    public function testNudgeDeltaComesAfterFallbackText(): void
    {
        $fake = new class implements LLMProvider, StreamingLLMProvider {
            public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
            {
                throw new LogicException('chat() is not used by the answer stream');
            }

            public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
            {
                $onDelta('Bạn có thể ');
                throw new LLMTransportException('provider_unavailable', 'x');
            }
        };
        $stack = new MiddlewareStack([new AnswerTemplateFallback(), $this->middleware()]);
        $deltas = [];
        $turn = $this->turn(1, $deltas);

        $generated = (new StreamingResponseGenerator())->stream(
            new MiddlewareModel($fake, $stack),
            $turn->message,
            $this->turnResult()->toArray(),
            $turn->emit(...)
        );
        $result = $this->turnResult()->with('message', $generated['text'])->with('answer', $generated['text']);
        $out = $stack->afterTurn($turn, $result)->toArray();

        $this->assertSame(['Bạn có thể ', "\n\n" . self::DRAFT, ' ' . self::NUDGE], $deltas);
        $this->assertSame(implode('', $deltas), $out['message']);
        $this->assertSame($out['message'], $out['answer']);
        $this->assertTrue($out['freeship_nudge']);
    }

    public function testFactoryBuildsTheOptionalMiddlewareFromTheHarnessPolicy(): void
    {
        $factory = new AgentFactory(new HarnessConfig());

        $this->assertInstanceOf(FreeShippingNudge::class, $factory->makeMiddleware(FreeShippingNudge::class, $this->pdo, 9));
        $this->assertFalse(FreeShippingNudge::REQUIRED);
        $this->assertNotContains(FreeShippingNudge::class, AgentFactory::REQUIRED_MIDDLEWARE);
        $this->assertSame(
            ['CheckoutGuardrail', 'AnswerTemplateFallback', 'FreeShippingNudge', 'TraceOutbox'],
            $factory->middlewareStack($this->pdo, 9)->names()
        );
    }
}
