<?php
/**
 * LLM Provider Interface
 * Abstraction cho các LLM API (OpenAI-compatible, Claude, Gemini)
 */

interface LLMProvider {
    /**
     * Gửi conversation + tools → LLM → response
     * @param array $messages   [['role'=>'user'|'assistant'|'system', 'content'=>'...']]
     * @param array $tools      Tool definitions (OpenAI format)
     * @param string $toolChoice 'auto'|'none'|'required'
     * @return LLMResponse
     */
    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse;
}

final class LLMTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
