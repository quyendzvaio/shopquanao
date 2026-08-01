<?php

require_once __DIR__ . '/contracts/ChatbotConversationStore.php';

class PdoChatbotConversationStore implements ChatbotConversationStore
{
    public function __construct(
        private PDO $pdo,
        private int $sessionId
    ) {
    }

    public function findLastProductId(): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT metadata FROM chat_messages
                 WHERE session_id = ? AND role = 'bot' AND metadata IS NOT NULL
                 ORDER BY id DESC LIMIT 8"
            );
            $stmt->execute([$this->sessionId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $metadata = json_decode((string)($row['metadata'] ?? ''), true);
                $first = is_array($metadata) && is_array($metadata['products'] ?? null)
                    ? ($metadata['products'][0] ?? null)
                    : null;
                if (is_array($first) && !empty($first['id'])) {
                    return (int)$first['id'];
                }
            }
        } catch (Throwable $e) {
            error_log('Last product lookup failed: ' . $e->getMessage());
        }

        return null;
    }

    public function saveMessages(
        string $userMessage,
        string $botMessage,
        array $products,
        array $knowledgeSources,
        array $evaluationMetadata,
        array $responseMetadata
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'user', ?, ?)"
            );
            $stmt->execute([
                $this->sessionId,
                $userMessage,
                json_encode(['pipeline' => 'deterministic_hybrid'], JSON_UNESCAPED_UNICODE),
            ]);

            $metadata = [];
            if ($products !== []) {
                $metadata['products'] = $products;
            }
            if ($knowledgeSources !== []) {
                $metadata['knowledge_sources'] = $knowledgeSources;
            }
            if ($evaluationMetadata !== []) {
                $metadata['evaluation'] = $evaluationMetadata;
            }
            if ($responseMetadata !== []) {
                $metadata['pipeline'] = $responseMetadata;
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'bot', ?, ?)"
            );
            $stmt->execute([
                $this->sessionId,
                $botMessage,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            ]);
            $this->pdo->prepare(
                'UPDATE chat_sessions SET updated_at = ' . $this->sqlNow() . ' WHERE id = ?'
            )->execute([$this->sessionId]);
        } catch (Throwable $e) {
            error_log('Save message error: ' . $e->getMessage());
        }
    }

    public function logToolExecution(
        string $tool,
        array $arguments,
        mixed $result,
        int $durationMs,
        bool $success
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO tool_executions
                    (session_id, tool_name, arguments, result, duration_ms, success, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, " . $this->sqlNow() . ')'
            );
            $stmt->execute([
                $this->sessionId,
                $tool,
                json_encode($arguments, JSON_UNESCAPED_UNICODE),
                is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : $result,
                $durationMs,
                $success ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            error_log('Tool execution log failed: ' . $e->getMessage());
        }
    }

    private function sqlNow(): string
    {
        try {
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'CURRENT_TIMESTAMP'
                : 'NOW()';
        } catch (Throwable) {
            return 'NOW()';
        }
    }
}
