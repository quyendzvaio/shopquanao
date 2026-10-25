<?php

/** GET /api/chatbot/history: the logged-in user's latest active chat session and its messages. */
final class ChatHistoryService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * The response body of GET /api/chatbot/history. History belongs to the
     * bearer user only; a guest session token never grants it, so
     * $sessionToken is accepted for symmetry with ChatbotService::open() and
     * not used. The caller has already authenticated the bearer token.
     *
     * @return array<string,mixed> messages, session_token and (when a session exists) session_id
     */
    public function forRequest(?string $sessionToken, ?string $bearerToken): array
    {
        $userId = $bearerToken !== null && $bearerToken !== ''
            ? (new UserRepository($this->pdo))->idByApiToken($bearerToken)
            : null;
        $session = $userId !== null ? (new ChatSessionRepository($this->pdo))->latestActiveForUser($userId) : null;
        if (!$session) {
            return ['messages' => [], 'session_token' => null];
        }

        $sessionId = (int)$session['id'];
        $sessionToken = $session['session_token'];

        $messages = [];
        foreach ((new ChatConversationRepository($this->pdo))->messagesForSession($sessionId) as $r) {
            $msg = [
                'id' => (int)$r['id'],
                'role' => $r['role'],
                'message' => $r['message'],
                'created_at' => $r['created_at'],
                'products' => [],
            ];
            $meta = $r['metadata'] ? json_decode($r['metadata'], true) : [];
            if ($meta && isset($meta['products'])) {
                $msg['products'] = $meta['products'];
            }
            $messages[] = $msg;
        }

        return [
            'messages' => $messages,
            'session_token' => $sessionToken,
            'session_id' => $sessionId,
        ];
    }
}
