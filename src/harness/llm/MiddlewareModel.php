<?php

/**
 * Decorates a real provider so every call passes through MiddlewareStack::wrapModelCall.
 * Callers may set $options['harness'] = ['purpose' => ..., 'grounded_draft' => ?string];
 * that key is removed before the provider is called.
 */
final class MiddlewareModel implements LLMProvider, StreamingLLMProvider
{
    public function __construct(private LLMProvider $inner, private MiddlewareStack $stack)
    {
    }

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
    {
        $call = $this->modelCall($messages, $tools, $toolChoice, $options, null, false);
        return $this->stack->wrapModelCall(
            $call,
            fn (ModelCall $call): LLMResponse => $this->inner->chat($call->messages, $call->tools, $call->toolChoice, $call->options)
        );
    }

    public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
    {
        $inner = $this->inner;
        if (!$inner instanceof StreamingLLMProvider) {
            throw new LogicException('Wrapped provider does not support streaming');
        }
        $call = $this->modelCall($messages, [], 'auto', $options, Closure::fromCallable($onDelta), true);
        return $this->stack->wrapModelCall(
            $call,
            static fn (ModelCall $call): LLMResponse => $inner->chatStream($call->messages, $call->onDelta ?? $onDelta, $call->options)
        );
    }

    private function modelCall(array $messages, array $tools, string $toolChoice, array $options, ?Closure $onDelta, bool $stream): ModelCall
    {
        $harness = is_array($options['harness'] ?? null) ? $options['harness'] : [];
        unset($options['harness']);
        $draft = $harness['grounded_draft'] ?? null;
        return new ModelCall(
            purpose: (string) ($harness['purpose'] ?? 'unspecified'),
            messages: $messages,
            tools: $tools,
            toolChoice: $toolChoice,
            options: $options,
            groundedDraft: $draft === null ? null : (string) $draft,
            onDelta: $onDelta,
            stream: $stream,
        );
    }
}
