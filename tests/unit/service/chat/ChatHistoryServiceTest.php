<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** GET /api/chatbot/history returns private conversations: only the bearer user's own latest active session. */
final class ChatHistoryServiceTest extends TestCase
{
    private const EMPTY_BODY = ['messages' => [], 'session_token' => null];

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, api_token TEXT)');
        $pdo->exec("CREATE TABLE chat_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, session_token TEXT,
            status TEXT DEFAULT 'active', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, role TEXT DEFAULT 'user',
            message TEXT, metadata TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO users (id, username, api_token) VALUES (1, 'A', 'token-a'), (2, 'B', 'token-b')");

        return $pdo;
    }

    private function session(PDO $pdo, ?int $userId, string $token, string $updatedAt, string $status = 'active'): int
    {
        $pdo->prepare('INSERT INTO chat_sessions (user_id, session_token, status, updated_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $token, $status, $updatedAt]);

        return (int) $pdo->lastInsertId();
    }

    private function message(PDO $pdo, int $sessionId, string $role, string $text, ?string $metadata = null): void
    {
        $pdo->prepare('INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, ?, ?, ?)')
            ->execute([$sessionId, $role, $text, $metadata]);
    }

    public function testBearerUserSeesOnlyTheirOwnLatestActiveSession(): void
    {
        $pdo = $this->pdo();
        $old = $this->session($pdo, 1, 'a-old', '2026-01-01 10:00:00');
        $latest = $this->session($pdo, 1, 'a-latest', '2026-02-01 10:00:00');
        $closed = $this->session($pdo, 1, 'a-closed', '2026-03-01 10:00:00', 'closed');
        $other = $this->session($pdo, 2, 'b-latest', '2026-04-01 10:00:00');
        $this->message($pdo, $old, 'user', 'old A message');
        $this->message($pdo, $closed, 'user', 'closed A message');
        $this->message($pdo, $other, 'user', 'secret B message');
        $this->message($pdo, $latest, 'user', 'hello from A');
        $this->message($pdo, $latest, 'bot', 'hi A', '{"products":[{"id":7}]}');

        $body = (new ChatHistoryService($pdo))->forRequest(null, 'token-a');

        $this->assertSame($latest, $body['session_id']);
        $this->assertSame('a-latest', $body['session_token']);
        $this->assertSame(['hello from A', 'hi A'], array_column($body['messages'], 'message'));
        $this->assertSame(['user', 'bot'], array_column($body['messages'], 'role'));
        $this->assertSame([], $body['messages'][0]['products']);
        $this->assertSame([['id' => 7]], $body['messages'][1]['products']);
    }

    public function testEachBearerUserGetsOnlyTheirOwnSession(): void
    {
        $pdo = $this->pdo();
        $this->message($pdo, $this->session($pdo, 1, 'a-sess', '2026-01-01 10:00:00'), 'user', 'A only');
        $this->message($pdo, $this->session($pdo, 2, 'b-sess', '2026-05-01 10:00:00'), 'user', 'B only');
        $service = new ChatHistoryService($pdo);

        $this->assertSame(['A only'], array_column($service->forRequest(null, 'token-a')['messages'], 'message'));
        $this->assertSame(['B only'], array_column($service->forRequest(null, 'token-b')['messages'], 'message'));
    }

    public function testUnknownBearerTokenGetsTheEmptyBody(): void
    {
        $pdo = $this->pdo();
        $this->message($pdo, $this->session($pdo, 1, 'a-sess', '2026-01-01 10:00:00'), 'user', 'private');
        $service = new ChatHistoryService($pdo);

        $this->assertSame(self::EMPTY_BODY, $service->forRequest(null, 'no-such-token'));
        $this->assertSame(self::EMPTY_BODY, $service->forRequest('a-sess', 'no-such-token'));
        $this->assertSame(self::EMPTY_BODY, $service->forRequest(null, ''));
        $this->assertSame(self::EMPTY_BODY, $service->forRequest(null, null));
    }

    public function testGuestSessionTokenAloneNeverGrantsHistory(): void
    {
        $pdo = $this->pdo();
        $this->message($pdo, $this->session($pdo, null, 'guest-sess', '2026-01-01 10:00:00'), 'user', 'guest message');
        $this->message($pdo, $this->session($pdo, 1, 'a-sess', '2026-02-01 10:00:00'), 'user', 'A private');
        $service = new ChatHistoryService($pdo);

        $this->assertSame(self::EMPTY_BODY, $service->forRequest('guest-sess', null));
        $this->assertSame(self::EMPTY_BODY, $service->forRequest('a-sess', null), "another user's session token must not grant it either");
    }

    public function testBearerUserWithoutAnActiveSessionGetsTheEmptyBody(): void
    {
        $pdo = $this->pdo();
        $this->session($pdo, 1, 'a-closed', '2026-01-01 10:00:00', 'closed');

        $this->assertSame(self::EMPTY_BODY, (new ChatHistoryService($pdo))->forRequest(null, 'token-a'));
    }

    public function testBearerUserWinsOverAGuestSessionToken(): void
    {
        $pdo = $this->pdo();
        $this->message($pdo, $this->session($pdo, null, 'guest-sess', '2026-03-01 10:00:00'), 'user', 'guest message');
        $mine = $this->session($pdo, 1, 'a-sess', '2026-01-01 10:00:00');

        $body = (new ChatHistoryService($pdo))->forRequest('guest-sess', 'token-a');

        $this->assertSame($mine, $body['session_id']);
        $this->assertSame([], $body['messages']);
    }
}
