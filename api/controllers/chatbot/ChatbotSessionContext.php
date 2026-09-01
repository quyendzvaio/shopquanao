<?php

/**
 * Resolves the durable chat session used by both JSON and streamed chatbot
 * requests. Keeping this at one seam prevents WebSocket delivery from creating
 * a different session/identity model than the existing HTTP endpoint.
 */
final class ChatbotSessionContext
{
    public function __construct(
        public readonly int $sessionId,
        public readonly string $sessionToken,
        public readonly ?int $userId
    ) {
    }

    public static function resolve(PDO $pdo, string $requestedSessionToken, ?string $bearerToken): self
    {
        $userId = null;
        if ($bearerToken !== null && $bearerToken !== '') {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE api_token = ?');
            $stmt->execute([$bearerToken]);
            $user = $stmt->fetch();
            $userId = $user ? (int) $user['id'] : null;
        }

        if ($userId !== null) {
            $stmt = $pdo->prepare("SELECT id, session_token FROM chat_sessions WHERE user_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch();
            if ($existing) {
                return new self((int) $existing['id'], (string) $existing['session_token'], $userId);
            }

            $sessionToken = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare('INSERT INTO chat_sessions (user_id, session_token) VALUES (?, ?)');
            $stmt->execute([$userId, $sessionToken]);
            return new self((int) $pdo->lastInsertId(), $sessionToken, $userId);
        }

        if ($requestedSessionToken !== '') {
            $stmt = $pdo->prepare("SELECT id FROM chat_sessions WHERE session_token = ? AND status = 'active'");
            $stmt->execute([$requestedSessionToken]);
            $row = $stmt->fetch();
            if ($row) {
                return new self((int) $row['id'], $requestedSessionToken, null);
            }
        }

        $sessionToken = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('INSERT INTO chat_sessions (user_id, session_token) VALUES (NULL, ?)');
        $stmt->execute([$sessionToken]);
        return new self((int) $pdo->lastInsertId(), $sessionToken, null);
    }

    public function touch(PDO $pdo): void
    {
        if ($this->userId !== null) {
            $pdo->prepare('UPDATE chat_sessions SET user_id = ? WHERE id = ? AND user_id IS NULL')
                ->execute([$this->userId, $this->sessionId]);
        }
        $pdo->prepare('UPDATE chat_sessions SET updated_at = NOW(), user_id = COALESCE(?, user_id) WHERE id = ?')
            ->execute([$this->userId, $this->sessionId]);
    }
}
