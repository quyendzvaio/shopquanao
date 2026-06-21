<?php
/**
 * LLM Response DTO
 */
class LLMResponse {
    public string $content;
    public string $finishReason; // 'stop'|'tool_calls'|'length'
    public array $toolCalls;     // ToolCall[]
    public ?array $usage;        // ['prompt_tokens'=>N, 'completion_tokens'=>N]

    public function __construct(
        string $content = '',
        string $finishReason = 'stop',
        array $toolCalls = [],
        ?array $usage = null
    ) {
        $this->content = $content;
        $this->finishReason = $finishReason;
        $this->toolCalls = $toolCalls;
        $this->usage = $usage;
    }

    public function hasToolCalls(): bool {
        return !empty($this->toolCalls);
    }

    public function getFirstToolCall(): ?ToolCall {
        return $this->toolCalls[0] ?? null;
    }
}

class ToolCall {
    public string $id;
    public string $name;
    public array $arguments; // decoded assoc array

    public function __construct(string $id, string $name, array $arguments) {
        $this->id = $id;
        $this->name = $name;
        $this->arguments = $arguments;
    }
}
