<?php

interface ChatbotConversationStore
{
    public function findLastProductId(): ?int;

    public function saveMessages(
        string $userMessage,
        string $botMessage,
        array $products,
        array $knowledgeSources,
        array $evaluationMetadata,
        array $responseMetadata
    ): void;

    public function logToolExecution(
        string $tool,
        array $arguments,
        mixed $result,
        int $durationMs,
        bool $success
    ): void;
}
