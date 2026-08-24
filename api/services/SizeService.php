<?php

require_once __DIR__ . '/../controllers/chatbot/ToolRegistry.php';

/** Transitional application-service boundary for size recommendations. */
final class SizeService
{
    private ToolRegistry $legacy;

    public function __construct(PDO $pdo)
    {
        $this->legacy = new ToolRegistry($pdo);
    }

    public function suggest(array $arguments): array
    {
        return $this->legacy->execute('suggest_size', $arguments);
    }
}
