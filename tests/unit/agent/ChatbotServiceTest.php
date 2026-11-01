<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EnvSandbox.php';

/** The chat facade: the session seam (open, errorCategory) and one turn through chat-graph with a fake transport. */
final class ChatbotServiceTest extends TestCase
{
    use EnvSandbox;

    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        $this->cleanEnv();
        // Busy turns log a chat_graph line; keep it out of the test output.
        $this->previousErrorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
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
        $pdo->exec("CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, role TEXT DEFAULT 'user',
            message TEXT, metadata TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO users (id, username, api_token) VALUES (1, 'A', 'token-a')");

        return $pdo;
    }

    private const BUSY = 'Hệ thống đang bận, bạn thử lại sau ít phút nhé.';

    /** @param array<string,mixed> $complete fields merged over a default complete event */
    private function completeEvent(array $complete = []): array
    {
        return $complete + [
            'type' => 'complete', 'response_type' => 'final_answer', 'primary_intent' => 'product_search',
            'tools_used' => ['search_products'], 'outfits' => [], 'offered' => [], 'freeship_nudge' => false,
            'freeship_fingerprint' => null, 'trace_id' => 'trace-1', 'latency' => ['total_ms' => 5],
        ];
    }

    /**
     * A client whose transport answers one NDJSON stream per turn and records every request body.
     *
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $requests
     */
    private function client(array $events, array &$requests = [], ?Throwable $failure = null): ChatGraphClient
    {
        return new ChatGraphClient('http://graph:8090', 't', 5, static function (string $url, array $headers, string $body, int $timeout, callable $onChunk) use ($events, &$requests, $failure): int {
            $requests[] = json_decode($body, true);
            if ($failure !== null) {
                throw $failure;
            }
            if (end($requests)['stream'] === false) {
                $result = ['message' => '', 'products' => []];
                foreach ($events as $event) {
                    $result['message'] .= $event['text'] ?? '';
                    $result['products'] = $event['products'] ?? $result['products'];
                    $result = ($event['type'] === 'complete' ? array_diff_key($event, ['type' => 1]) : []) + $result;
                }
                $onChunk(json_encode($result, JSON_UNESCAPED_UNICODE));
                return 200;
            }
            foreach ($events as $event) {
                $onChunk(json_encode($event, JSON_UNESCAPED_UNICODE) . "\n");
            }
            return 200;
        });
    }

    private function service(PDO $pdo, ChatGraphClient $client, ?int $userId = null): ChatbotService
    {
        $pdo->exec('INSERT INTO chat_sessions (id, user_id, session_token) VALUES (5, ' . ($userId ?? 'NULL') . ", 'tok-5')");

        return new ChatbotService($pdo, 5, $userId, $client);
    }

    /** @return list<array<string,mixed>> */
    private function messages(PDO $pdo): array
    {
        return $pdo->query('SELECT role, message, metadata FROM chat_messages WHERE session_id = 5 ORDER BY id')->fetchAll();
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

    public function testRespondSendsTheWhitelistedRequestAndSavesTheTurn(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $card = ['id' => 73, 'name' => 'Quần Kaki', 'price' => 490000, 'stock' => 3];
        $client = $this->client([
            ['type' => 'delta', 'text' => 'Đây là quần.'],
            ['type' => 'cards', 'products' => [$card]],
            $this->completeEvent(),
        ], $requests);

        $result = $this->service($pdo, $client)->respond('quần baggy cho nữ size M');

        $this->assertNull($requests[0]['user_id']);
        $this->assertSame(5, $requests[0]['session_id']);
        $this->assertSame('quần baggy cho nữ size M', $requests[0]['message']);
        $this->assertFalse($requests[0]['stream']);
        $this->assertSame('M', $requests[0]['memory']['slots']['size']);
        $this->assertSame('nữ', $requests[0]['memory']['slots']['gender']);
        $this->assertArrayNotHasKey('product_type', $requests[0]['memory']['slots']);
        $this->assertNull($requests[0]['memory']['profile']);
        $this->assertSame('Đây là quần.', $result['message']);
        $this->assertSame('Đây là quần.', $result['answer']);
        $this->assertSame([$card], $result['products']);
        $this->assertSame([$card], $result['cards']);
        $this->assertSame('final_answer', $result['response_type']);
        $this->assertSame('product_search', $result['primary_intent']);
        $this->assertSame('trace-1', $result['trace_id']);
        $this->assertSame([], $result['outfits']);
        $this->assertFalse($result['freeship_nudge']);
        $rows = $this->messages($pdo);
        $this->assertSame(['user', 'bot'], array_column($rows, 'role'));
        $this->assertSame(['quần baggy cho nữ size M', 'Đây là quần.'], array_column($rows, 'message'));
        $this->assertSame([$card], json_decode($rows[1]['metadata'], true)['products']);
    }

    public function testStreamingForwardsDeltasAndSkipsProgress(): void
    {
        $pdo = $this->pdo();
        $client = $this->client([
            ['type' => 'progress', 'stage' => 'graph'],
            ['type' => 'delta', 'text' => 'Chào '],
            ['type' => 'delta', 'text' => 'bạn'],
            $this->completeEvent(),
        ]);
        $deltas = [];

        $result = $this->service($pdo, $client)->respondStreaming('chào', static function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        });

        $this->assertSame(['Chào ', 'bạn'], $deltas);
        $this->assertSame('Chào bạn', $result['message']);
    }

    public function testBusyWhenGraphDown(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $client = $this->client([], $requests, new RuntimeException('connection refused'));
        $log = tempnam(sys_get_temp_dir(), 'graph_log');
        $previous = ini_set('error_log', $log);

        try {
            $result = $this->service($pdo, $client)->respond('áo thun');
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }

        $this->assertSame('busy', $result['response_type']);
        $this->assertSame(self::BUSY, $result['message']);
        $this->assertSame([], $result['products']);
        $this->assertStringContainsString('"operation":"chat_graph","success":false,"error_category":"transport"', $logged);
        $rows = $this->messages($pdo);
        $this->assertSame(['user', 'bot'], array_column($rows, 'role'));
        $this->assertSame(['áo thun', self::BUSY], array_column($rows, 'message'));
    }

    public function testBusyStreamEmitsTheBusyTextAsOneDelta(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $client = $this->client([], $requests, new RuntimeException('down'));
        $deltas = [];

        $result = $this->service($pdo, $client)->respondStreaming('áo thun', static function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        });

        $this->assertSame([self::BUSY], $deltas);
        $this->assertSame('busy', $result['response_type']);
    }

    public function testBusyAfterPartialDeltasDoesNotRepeatTheBusyText(): void
    {
        $pdo = $this->pdo();
        $client = $this->client([['type' => 'delta', 'text' => 'Chào']]);
        $deltas = [];

        $result = $this->service($pdo, $client)->respondStreaming('chào', static function (string $d) use (&$deltas): void {
            $deltas[] = $d;
        });

        $this->assertSame(['Chào'], $deltas);
        $this->assertSame('busy', $result['response_type']);
        $this->assertSame(self::BUSY, $result['message']);
    }

    public function testOfferedSavedOutsideProducts(): void
    {
        $pdo = $this->pdo();
        $offered = [['id' => 73, 'name' => 'Quần Kaki'], ['id' => 71, 'name' => 'Quần Bò']];
        $client = $this->client([
            ['type' => 'delta', 'text' => 'Mẫu A đang hết hàng. Bạn có muốn xem Quần Kaki hoặc Quần Bò không?'],
            $this->completeEvent(['response_type' => 'clarification', 'offered' => $offered]),
        ]);

        $this->service($pdo, $client)->respond('quần baggy còn hàng không');

        $metadata = json_decode($this->messages($pdo)[1]['metadata'], true);
        $this->assertSame($offered, $metadata['offered']);
        $this->assertArrayNotHasKey('products', $metadata);
        $this->assertSame('clarification', $metadata['pipeline']['response_type']);
        $this->assertSame(['search_products'], $metadata['pipeline']['tools_used']);
        $this->assertArrayNotHasKey('offered', $metadata['pipeline']);
    }

    public function testFingerprintRemembered(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $client = $this->client([
            ['type' => 'delta', 'text' => 'ok'],
            $this->completeEvent(['freeship_nudge' => true, 'freeship_fingerprint' => 'fp-9']),
        ], $requests);
        $service = $this->service($pdo, $client);

        $result = $service->respond('áo thun');
        $service->respond('áo sơ mi');

        $this->assertTrue($result['freeship_nudge']);
        $this->assertNull($requests[0]['freeship_fingerprint']);
        $this->assertSame('fp-9', $requests[1]['freeship_fingerprint']);
        $slots = json_decode((string) $pdo->query('SELECT slots FROM chat_session_memory WHERE session_id = 5')->fetchColumn(), true);
        $this->assertSame('fp-9', $slots['freeship_nudged_fingerprint']);
        $this->assertArrayNotHasKey('freeship_nudged_fingerprint', $requests[1]['memory']['slots']);
    }

    public function testNullFingerprintLeavesTheStoredOneAlone(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $client = $this->client([['type' => 'delta', 'text' => 'ok'], $this->completeEvent(['freeship_fingerprint' => 'fp-1'])], $requests);
        $service = $this->service($pdo, $client);
        $service->respond('áo thun');
        $noFingerprint = $this->client([['type' => 'delta', 'text' => 'ok'], $this->completeEvent()], $requests);
        $second = new ChatbotService($pdo, 5, null, $noFingerprint);

        $second->respond('áo thun nữa');

        $slots = json_decode((string) $pdo->query('SELECT slots FROM chat_session_memory WHERE session_id = 5')->fetchColumn(), true);
        $this->assertSame('fp-1', $slots['freeship_nudged_fingerprint']);
    }

    public function testHistoryExcludesCurrentTurn(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $client = $this->client([['type' => 'delta', 'text' => 'ok'], $this->completeEvent()], $requests);
        $service = $this->service($pdo, $client);
        $insert = $pdo->prepare('INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (5, ?, ?, ?)');
        $insert->execute(['user', 'quần baggy', null]);
        $insert->execute(['bot', 'Đây ạ.', json_encode(['products' => [['id' => 73, 'name' => 'Quần Kaki']]], JSON_UNESCAPED_UNICODE)]);

        $service->respond('cái thứ nhất');

        $this->assertSame([
            ['role' => 'user', 'content' => 'quần baggy'],
            ['role' => 'assistant', 'content' => "Đây ạ.\n[Thẻ đã hiện: mã 73 Quần Kaki]"],
        ], $requests[0]['history']);
        $this->assertCount(4, $this->messages($pdo));
    }

    public function testLoggedInUserSendsTheirIdAndAProfile(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $client = $this->client([['type' => 'delta', 'text' => 'ok'], $this->completeEvent()], $requests);

        $this->service($pdo, $client, 1)->respond('mình hay mặc M');

        $this->assertSame(1, $requests[0]['user_id']);
        $this->assertSame('M', $requests[0]['memory']['profile']['usual_size']);
        $this->assertStringNotContainsString('feedback', json_encode($requests[0]['memory']));
    }

    public function testClientDisconnectDuringStreamStillPersistsTheTurn(): void
    {
        $pdo = $this->pdo();
        $client = $this->client([
            ['type' => 'delta', 'text' => 'Chào '],
            ['type' => 'delta', 'text' => 'bạn'],
            $this->completeEvent(['freeship_fingerprint' => 'fp-3']),
        ]);
        $calls = 0;

        $result = $this->service($pdo, $client)->respondStreaming('chào', static function (string $d) use (&$calls): void {
            $calls++;
            throw new RuntimeException('client gone');
        });

        $this->assertSame(1, $calls);
        $this->assertSame('final_answer', $result['response_type']);
        $this->assertSame('Chào bạn', $result['message']);
        $this->assertSame(['user', 'bot'], array_column($this->messages($pdo), 'role'));
        $slots = json_decode((string) $pdo->query('SELECT slots FROM chat_session_memory WHERE session_id = 5')->fetchColumn(), true);
        $this->assertSame('fp-3', $slots['freeship_nudged_fingerprint']);
    }

    public function testAnyGraphErrorAnswersBusyAndSavesBothMessages(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $graph = new class ('http://graph', 't', 5) extends ChatGraphClient {
            public function turn(array $request, ?callable $onEvent = null): array
            {
                throw new RuntimeException('unexpected');
            }
        };

        $result = $this->service($pdo, $graph)->respond('áo thun');

        $this->assertSame('busy', $result['response_type']);
        $this->assertSame(self::BUSY, $result['message']);
        $this->assertSame(['áo thun', self::BUSY], array_column($this->messages($pdo), 'message'));
    }

    public function testInvalidUtf8RequestAnswersBusy(): void
    {
        $pdo = $this->pdo();
        $requests = [];
        $client = $this->client([['type' => 'delta', 'text' => 'ok'], $this->completeEvent()], $requests);

        $result = $this->service($pdo, $client)->respond("áo \xB1\x31 thun");

        $this->assertSame('busy', $result['response_type']);
        $this->assertSame([], $requests);
        $this->assertCount(2, $this->messages($pdo));
    }
}
