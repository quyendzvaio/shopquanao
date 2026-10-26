<?php

/**
 * Stands in for the streaming LLM when none is configured (or the configured
 * one cannot stream). Every call fails with a transport error, so the streamed
 * answer takes the same path as any provider outage: the required
 * AnswerTemplateFallback middleware catches it and emits the grounded draft.
 */
final class UnavailableStreamingLlm implements LLMProvider, StreamingLLMProvider
{
    public const CATEGORY = 'llm_not_configured';

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
    {
        throw $this->unavailable();
    }

    public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
    {
        throw $this->unavailable();
    }

    private function unavailable(): LLMTransportException
    {
        return new LLMTransportException(self::CATEGORY, 'No streaming LLM provider is configured');
    }
}
