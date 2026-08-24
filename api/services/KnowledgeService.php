<?php

require_once __DIR__ . '/../controllers/chatbot/ToolRegistry.php';

/** Transitional application-service boundary for the existing hybrid RAG. */
final class KnowledgeService
{
    private ToolRegistry $legacy;

    public function __construct(PDO $pdo)
    {
        $this->legacy = new ToolRegistry($pdo);
    }

    public function retrieve(array $arguments): array
    {
        return $this->legacy->execute('retrieve_knowledge', $arguments);
    }
}
