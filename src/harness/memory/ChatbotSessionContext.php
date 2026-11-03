<?php

/**
 * Resolves the durable chat session used by both JSON and streamed chatbot
 * requests. Keeping this at one seam prevents SSE delivery from creating
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
        $sessions = new ChatSessionRepository($pdo);
        $userId = null;
        if ($bearerToken !== null && $bearerToken !== '') {
            $userId = (new UserRepository($pdo))->idByApiToken($bearerToken);
        }

        if ($userId !== null) {
            $existing = $sessions->latestActiveForUser($userId);
            if ($existing) {
                return new self((int) $existing['id'], (string) $existing['session_token'], $userId);
            }

            $sessionToken = bin2hex(random_bytes(32));
            return new self($sessions->createForUser($userId, $sessionToken), $sessionToken, $userId);
        }

        if ($requestedSessionToken !== '') {
            $sessionId = $sessions->findActiveByToken($requestedSessionToken);
            if ($sessionId !== null) {
                return new self($sessionId, $requestedSessionToken, null);
            }
        }

        $sessionToken = bin2hex(random_bytes(32));
        return new self($sessions->createAnonymous($sessionToken), $sessionToken, null);
    }

    public function touch(PDO $pdo): void
    {
        $sessions = new ChatSessionRepository($pdo);
        if ($this->userId !== null) {
            $sessions->attachUser($this->sessionId, $this->userId);
        }
        $sessions->touch($this->sessionId, $this->userId);
    }
}
