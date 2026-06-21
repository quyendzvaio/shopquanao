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
    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto'): LLMResponse;
}
