<?php
/**
 * LLM Response DTO
 */
class LLMResponse {
    public string $content;
    public string $finishReason; // 'stop'|'tool_calls'|'length'
    public array $toolCalls;     // ToolCall[]
    public ?array $usage;        // ['prompt_tokens'=>N, 'completion_tokens'=>N]
    public ?string $fallbackReason; // set when the answer came from a fallback instead of the LLM

    public function __construct(
        string $content = '',
        string $finishReason = 'stop',
        array $toolCalls = [],
        ?array $usage = null,
        ?string $fallbackReason = null
    ) {
        $this->content = $content;
        $this->finishReason = $finishReason;
        $this->toolCalls = $toolCalls;
        $this->usage = $usage;
        $this->fallbackReason = $fallbackReason;
    }

    public function hasToolCalls(): bool {
        return !empty($this->toolCalls);
    }

    public function getFirstToolCall(): ?ToolCall {
        return $this->toolCalls[0] ?? null;
    }
}
