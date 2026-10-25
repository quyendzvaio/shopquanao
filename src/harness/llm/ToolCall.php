<?php
/**
 * A tool invocation requested by the LLM.
 */
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
