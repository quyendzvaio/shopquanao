<?php

/** Reads and writes chat_sessions: one durable chat session per guest token or logged-in user. */
final class ChatSessionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int|string,mixed>|null id and session_token of the user's most recently updated active session (PDO default fetch mode) */
    public function latestActiveForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, session_token FROM chat_sessions WHERE user_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return int|null id of the active session holding this token */
    public function findActiveByToken(string $token): ?int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM chat_sessions WHERE session_token = ? AND status = 'active'");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    /** @return int id of the new session owned by the user */
    public function createForUser(int $userId, string $token): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO chat_sessions (user_id, session_token) VALUES (?, ?)');
        $stmt->execute([$userId, $token]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return int id of the new guest session */
    public function createAnonymous(string $token): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO chat_sessions (user_id, session_token) VALUES (NULL, ?)');
        $stmt->execute([$token]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Gives a guest session to the user who just logged in. */
    public function attachUser(int $id, int $userId): void
    {
        $this->pdo->prepare('UPDATE chat_sessions SET user_id = ? WHERE id = ? AND user_id IS NULL')
            ->execute([$userId, $id]);
    }

    /** End-of-request touch from the chatbot endpoints (MariaDB NOW()). */
    public function touch(int $id, ?int $userId): void
    {
        $this->pdo->prepare('UPDATE chat_sessions SET updated_at = NOW(), user_id = COALESCE(?, user_id) WHERE id = ?')
            ->execute([$userId, $id]);
    }

    /** Touch after a turn's messages are saved (CURRENT_TIMESTAMP on sqlite, otherwise NOW()). */
    public function touchUpdatedAt(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE chat_sessions SET updated_at = ' . $this->sqlNow() . ' WHERE id = ?'
        )->execute([$id]);
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
