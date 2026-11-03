<?php

/** Reads and writes chat_messages (the turn transcript) and tool_executions (tool diagnostics). */
final class ChatConversationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<int|string,mixed>> every message of the session, oldest first (PDO default fetch mode) */
    public function messagesForSession(int $sessionId): array
    {
        $stmt = $this->pdo->prepare("
    SELECT id, role, message, metadata, created_at
    FROM chat_messages
    WHERE session_id = ?
    ORDER BY id ASC
");
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> metadata of the session's last 8 bot messages that have any, newest first */
    public function recentBotMetadata(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT metadata FROM chat_messages
                 WHERE session_id = ? AND role = 'bot' AND metadata IS NOT NULL
                 ORDER BY id DESC LIMIT 8"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addUserMessage(int $sessionId, string $message, string|false|null $metadata): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'user', ?, ?)"
        );
        $stmt->execute([$sessionId, $message, $metadata]);
    }

    public function addBotMessage(int $sessionId, string $message, string|false|null $metadata): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'bot', ?, ?)"
        );
        $stmt->execute([$sessionId, $message, $metadata]);
    }

    /** Logs one tool run; created_at is CURRENT_TIMESTAMP on sqlite, otherwise NOW(). */
    public function logToolExecution(
        int $sessionId,
        string $tool,
        string|false $arguments,
        mixed $result,
        int $durationMs,
        int $success
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO tool_executions
                    (session_id, tool_name, arguments, result, duration_ms, success, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, " . $this->sqlNow() . ')'
        );
        $stmt->execute([$sessionId, $tool, $arguments, $result, $durationMs, $success]);
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
