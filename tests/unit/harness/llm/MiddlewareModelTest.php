<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MiddlewareModelTest extends TestCase
{
    private function recordingProvider(): LLMProvider
    {
        return new class implements LLMProvider, StreamingLLMProvider {
            public ?array $options = null;
            public ?array $messages = null;

            public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
            {
                $this->messages = $messages;
                $this->options = $options;
                return new LLMResponse('chat');
            }

            public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
            {
                $this->messages = $messages;
                $this->options = $options;
                $onDelta('tok');
                return new LLMResponse('stream');
            }
        };
    }

    public function testHarnessKeyIsStrippedBeforeProvider(): void
    {
        $inner = $this->recordingProvider();
        $model = new MiddlewareModel($inner, new MiddlewareStack([]));
        $options = ['temperature' => 0.2, 'harness' => ['purpose' => 'answer', 'grounded_draft' => 'draft']];

        $this->assertSame('chat', $model->chat([['role' => 'user', 'content' => 'hi']], [], 'auto', $options)->content);
        $this->assertFalse(array_key_exists('harness', $inner->options));
        $this->assertSame(['temperature' => 0.2], $inner->options);

        $deltas = [];
        $response = $model->chatStream([['role' => 'user', 'content' => 'hi']], function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        }, $options);
        $this->assertSame('stream', $response->content);
        $this->assertFalse(array_key_exists('harness', $inner->options));
        $this->assertSame(['tok'], $deltas);
    }

    public function testPurposeAndDraftReachMiddleware(): void
    {
        $spy = new class extends BaseMiddleware {
            /** @var list<ModelCall> */
            public array $calls = [];

            public function wrapModelCall(ModelCall $call, callable $next): LLMResponse
            {
                $this->calls[] = $call;
                return $next($call);
            }
        };
        $model = new MiddlewareModel($this->recordingProvider(), new MiddlewareStack([$spy]));

        $model->chat([], [['type' => 'function']], 'none', ['harness' => ['purpose' => 'input_parse'], 'x' => 1]);
        $model->chatStream([], static function (string $d): void {
        }, ['harness' => ['purpose' => 'answer', 'grounded_draft' => 'Bản nháp']]);
        $model->chat([]);

        [$chat, $stream, $plain] = $spy->calls;
        $this->assertSame('input_parse', $chat->purpose);
        $this->assertNull($chat->groundedDraft);
        $this->assertFalse($chat->stream);
        $this->assertSame('none', $chat->toolChoice);
        $this->assertSame([['type' => 'function']], $chat->tools);
        $this->assertSame(['x' => 1], $chat->options);
        $this->assertSame('answer', $stream->purpose);
        $this->assertSame('Bản nháp', $stream->groundedDraft);
        $this->assertTrue($stream->stream);
        $this->assertInstanceOf(Closure::class, $stream->onDelta);
        $this->assertSame('unspecified', $plain->purpose);
    }

    public function testMiddlewareCanReplaceOnDelta(): void
    {
        $inner = $this->recordingProvider();
        $wrapping = new class extends BaseMiddleware {
            public function wrapModelCall(ModelCall $call, callable $next): LLMResponse
            {
                $outer = $call->onDelta;
                return $next($call->withOnDelta(static function (string $d) use ($outer): void {
                    $outer('[' . $d . ']');
                }));
            }
        };
        $deltas = [];

        (new MiddlewareModel($inner, new MiddlewareStack([$wrapping])))->chatStream([], function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        });

        $this->assertSame(['[tok]'], $deltas);
    }

    public function testChatStreamOnNonStreamingProviderThrows(): void
    {
        $chatOnly = new class implements LLMProvider {
            public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
            {
                return new LLMResponse('chat');
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Wrapped provider does not support streaming');
        (new MiddlewareModel($chatOnly, new MiddlewareStack([])))->chatStream([], static function (string $d): void {
        });
    }
}
