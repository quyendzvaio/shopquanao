<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EnvSandbox.php';

/** The session seam of the chat facade: ChatbotService::open() and errorCategory(). No turn is run. */
final class ChatbotServiceTest extends TestCase
{
    use EnvSandbox;

    protected function setUp(): void
    {
        $this->cleanEnv();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, api_token TEXT)');
        $pdo->exec("CREATE TABLE chat_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, session_token TEXT,
            status TEXT DEFAULT 'active', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO users (id, username, api_token) VALUES (1, 'A', 'token-a')");

        return $pdo;
    }

    public function testBearerUserWinsOverTheGuestToken(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO chat_sessions (id, user_id, session_token) VALUES (10, NULL, 'guest-sess'), (11, 1, 'user-sess')");

        $service = ChatbotService::open($pdo, 'guest-sess', 'token-a');

        $this->assertSame(11, $service->sessionId());
        $this->assertSame('user-sess', $service->sessionToken());
    }

    public function testBearerUserWithoutASessionGetsANewOwnedOne(): void
    {
        $pdo = $this->pdo();

        $service = ChatbotService::open($pdo, 'guest-sess', 'token-a');

        $row = $pdo->query('SELECT user_id, session_token FROM chat_sessions WHERE id = ' . $service->sessionId())->fetch();
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame($service->sessionToken(), $row['session_token']);
        $this->assertNotSame('guest-sess', $service->sessionToken());
    }

    public function testGuestTokenOpensTheExistingGuestSession(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO chat_sessions (id, user_id, session_token) VALUES (10, NULL, 'guest-sess')");

        $service = ChatbotService::open($pdo, 'guest-sess', null);

        $this->assertSame(10, $service->sessionId());
        $this->assertSame('guest-sess', $service->sessionToken());
    }

    public function testUnknownBearerTokenFallsBackToAGuestSession(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO chat_sessions (id, user_id, session_token) VALUES (11, 1, 'user-sess')");

        $service = ChatbotService::open($pdo, null, 'no-such-token');

        $this->assertNotSame(11, $service->sessionId());
        $this->assertNotSame('user-sess', $service->sessionToken());
        $this->assertNull($pdo->query('SELECT user_id FROM chat_sessions WHERE id = ' . $service->sessionId())->fetchColumn());
    }

    public function testErrorCategoryIsTheTransportCategoryForLlmFailures(): void
    {
        $this->assertSame('timeout', ChatbotService::errorCategory(new LLMTransportException('timeout', 'slow')));
        $this->assertSame('rate_limited', ChatbotService::errorCategory(new LLMTransportException('rate_limited', 'busy', 3)));
    }

    public function testErrorCategoryIsRuntimeFailureForAnyOtherThrowable(): void
    {
        $this->assertSame('runtime_failure', ChatbotService::errorCategory(new RuntimeException('boom')));
        $this->assertSame('runtime_failure', ChatbotService::errorCategory(new PDOException('db')));
        $this->assertSame('runtime_failure', ChatbotService::errorCategory(new TypeError('type')));
    }
}
