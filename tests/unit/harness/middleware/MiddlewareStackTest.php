<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MiddlewareStackTest extends TestCase
{
    private function turn(): TurnContext
    {
        return new TurnContext('xin chào', 1, null, [], null);
    }

    public function testBeforeTurnStopsAtFirstBlocker(): void
    {
        $blocker = new class extends BaseMiddleware {
            public function beforeTurn(TurnContext $turn): ?TurnResult
            {
                return TurnResult::fromArray(['message' => 'blocked']);
            }
        };
        $second = new class extends BaseMiddleware {
            public bool $called = false;

            public function beforeTurn(TurnContext $turn): ?TurnResult
            {
                $this->called = true;
                return null;
            }
        };

        $result = (new MiddlewareStack([new class extends BaseMiddleware {}, $blocker, $second]))->beforeTurn($this->turn());

        $this->assertSame(['message' => 'blocked'], $result?->toArray());
        $this->assertFalse($second->called);
    }

    public function testBeforeTurnReturnsNullWhenNothingBlocks(): void
    {
        $this->assertNull((new MiddlewareStack([new class extends BaseMiddleware {}]))->beforeTurn($this->turn()));
        $this->assertNull((new MiddlewareStack([]))->beforeTurn($this->turn()));
    }

    public function testWrapModelCallIsOnionOrdered(): void
    {
        $trace = new ArrayObject();
        $tracer = static fn (string $name) => new class ($name, $trace) extends BaseMiddleware {
            public function __construct(private string $label, private ArrayObject $trace)
            {
            }

            public function wrapModelCall(ModelCall $call, callable $next): LLMResponse
            {
                $this->trace[] = $this->label . ':in';
                $response = $next($call);
                $this->trace[] = $this->label . ':out';
                return $response;
            }
        };
        $stack = new MiddlewareStack([$tracer('a'), $tracer('b')]);
        $call = new ModelCall('answer', [['role' => 'user', 'content' => 'hi']], [], 'auto', [], null, null, false);

        $response = $stack->wrapModelCall($call, function (ModelCall $call) use ($trace): LLMResponse {
            $trace[] = 'call';
            return new LLMResponse('done');
        });

        $this->assertSame(['a:in', 'b:in', 'call', 'b:out', 'a:out'], $trace->getArrayCopy());
        $this->assertSame('done', $response->content);
    }

    public function testAfterTurnChainsResults(): void
    {
        $append = static fn (string $suffix) => new class ($suffix) extends BaseMiddleware {
            public function __construct(private string $suffix)
            {
            }

            public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult
            {
                return $result->with('message', $result->get('message') . $this->suffix);
            }
        };

        $result = (new MiddlewareStack([$append('-a'), $append('-b')]))
            ->afterTurn($this->turn(), TurnResult::fromArray(['message' => 'x', 'products' => []]));

        $this->assertSame(['message' => 'x-a-b', 'products' => []], $result->toArray());
    }

    public function testOptionalAfterTurnFailureIsIsolated(): void
    {
        $failing = new class extends BaseMiddleware {
            public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult
            {
                throw new RuntimeException('boom');
            }

            public function name(): string
            {
                return 'Flaky';
            }
        };
        $after = new class extends BaseMiddleware {
            public bool $called = false;

            public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult
            {
                $this->called = true;
                return $result;
            }
        };
        $log = tempnam(sys_get_temp_dir(), 'mw');
        $previous = ini_set('error_log', $log);
        try {
            $result = (new MiddlewareStack([$failing, $after]))
                ->afterTurn($this->turn(), TurnResult::fromArray(['message' => 'kept']));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame(['message' => 'kept'], $result->toArray());
        $this->assertTrue($after->called);
        $this->assertStringContainsString(
            '{"operation":"middleware_Flaky","success":false,"error_category":"suppressed_runtime_failure"}',
            (string) file_get_contents($log)
        );
        unlink($log);
    }

    public function testRequiredAfterTurnFailurePropagates(): void
    {
        $required = new class extends BaseMiddleware {
            public const REQUIRED = true;

            public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult
            {
                throw new RuntimeException('required failed');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('required failed');
        (new MiddlewareStack([$required]))->afterTurn($this->turn(), TurnResult::fromArray([]));
    }

    public function testNamesUseShortClassNameByDefault(): void
    {
        $this->assertSame(['TestNamedMiddleware'], (new MiddlewareStack([new TestNamedMiddleware()]))->names());
    }

    public function testTurnContextEmitIsNoOpWithoutClosure(): void
    {
        $this->turn()->emit('ignored');
        $deltas = [];
        (new TurnContext('m', 1, null, [], function (string $delta) use (&$deltas): void {
            $deltas[] = $delta;
        }))->emit(' nudge');

        $this->assertSame([' nudge'], $deltas);
    }

    public function testTurnResultIsImmutable(): void
    {
        $original = TurnResult::fromArray(['message' => 'a']);
        $changed = $original->with('message', 'b');

        $this->assertSame('a', $original->get('message'));
        $this->assertSame('b', $changed->get('message'));
        $this->assertSame('fallback', $original->get('missing', 'fallback'));
    }
}

final class TestNamedMiddleware extends BaseMiddleware
{
}
