<?php

interface ChatbotMemoryStore
{
    public function ensureSchema(): void;

    public function rememberUserMessage(string $message): array;

    public function refreshSummary(): void;
}
