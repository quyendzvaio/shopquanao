<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/unit/agent/EnvSandbox.php';

/** The assembled harness (AgentFactory -> TurnRunner, ChatbotService facade) on shop_test: internal tools, no LLM. */
final class AgentAssemblyTest extends TestCase
{
    use EnvSandbox;

    /** Áo dài 470.000đ: a cart 30.000đ below the 500.000đ free-shipping threshold. */
    private const CART_PRODUCT_ID = 82;

    private ?PDO $pdo = null;
    private ?int $userId = null;
    private ?int $sessionId = null;
    private ?string $configFile = null;
    /** @var list<string> */
    private array $traceIds = [];

    protected function setUp(): void
    {
        $host = (string)(getenv('DB_HOST') ?: '');
        if ($host === '') {
            $this->markTestSkipped('DB_HOST not set; assembly test skipped');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_NAME') ?: 'shop_db'),
            (string)(getenv('DB_USER') ?: 'shop_user'),
            (string)(getenv('DB_PASS') ?: 'shop_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->cleanEnv();
        $this->setEnv('CHATBOT_TOOL_TRANSPORT', 'internal');
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $statements = [];
            if ($this->sessionId !== null) {
                $statements[] = ['DELETE FROM chat_messages WHERE session_id = ?', $this->sessionId];
                $statements[] = ['DELETE FROM tool_executions WHERE session_id = ?', $this->sessionId];
                $statements[] = ['DELETE FROM chat_session_memory WHERE session_id = ?', $this->sessionId];
                $statements[] = ['DELETE FROM chat_sessions WHERE id = ?', $this->sessionId];
            }
            if ($this->userId !== null) {
                $statements[] = ['DELETE FROM cart WHERE user_id = ?', $this->userId];
                $statements[] = ['DELETE FROM user_long_term_memory WHERE user_id = ?', $this->userId];
                $statements[] = ['DELETE FROM users WHERE id = ?', $this->userId];
            }
            foreach ($this->traceIds as $traceId) {
                $statements[] = ['DELETE FROM langfuse_trace_outbox WHERE trace_id = ?', $traceId];
            }
            foreach ($statements as [$sql, $id]) {
                $this->pdo->prepare($sql)->execute([$id]);
            }
        }
        if ($this->configFile !== null && is_file($this->configFile)) {
            unlink($this->configFile);
        }
        $this->restoreEnv();
    }

    public function testBlockedCheckoutTurnIsStillSaved(): void
    {
        $sessionId = $this->createSession(null);

        $result = $this->respond(new ChatbotService($this->pdo, $sessionId, null), 'thêm áo mã 50 vào giỏ giúp mình');

        $this->assertSame('unsupported_checkout', $result['primary_intent']);
        $this->assertSame(['user', 'bot'], $this->column('SELECT role FROM chat_messages WHERE session_id = ? ORDER BY id', $sessionId));
        $toolNames = array_map(
            static fn (array $definition): string => (string)$definition['function']['name'],
            ToolDefinitionCatalog::chatbotDefinitions()
        );
        $this->assertSame([], array_values(array_intersect(
            $this->column('SELECT tool_name FROM tool_executions WHERE session_id = ?', $sessionId),
            $toolNames
        )));
    }

    public function testBlockedTurnCarriesItsLoggedRoutingInLatency(): void
    {
        $sessionId = $this->createSession(null);

        $result = $this->respond(new ChatbotService($this->pdo, $sessionId, null), 'thêm áo mã 50 vào giỏ giúp mình');

        $routing = ['blocked_by' => 'CheckoutGuardrail', 'selected_tools' => []];
        $this->assertSame($routing, $result['latency']['routing']);
        $logged = $this->column("SELECT result FROM tool_executions WHERE session_id = ? AND tool_name = 'routing_pipeline'", $sessionId);
        $this->assertSame([$routing], array_map(static fn (string $json): array => json_decode($json, true), $logged));
    }

    public function testNudgeDisabledByConfig(): void
    {
        $userId = $this->createUserWithCart();
        $sessionId = $this->createSession($userId);
        $this->configFile = $this->middlewareConfig([CheckoutGuardrail::class, AnswerTemplateFallback::class]);
        $runner = (new AgentFactory(HarnessConfig::fromEnvironment(), $this->configFile))->turnRunner($this->pdo, $sessionId, $userId);

        $result = $runner->run(new TurnContext('tìm áo thun', $sessionId, $userId, [], null))->toArray();
        $this->traceIds[] = (string)$result['trace_id'];

        $this->assertSame('product_search', $result['primary_intent']);
        $this->assertEmpty($result['freeship_nudge'] ?? null);
        $this->assertStringNotContainsString('miễn phí ship', (string)$result['message']);
    }

    public function testMissingRequiredMiddlewareFailsFast(): void
    {
        $sessionId = $this->createSession(null);
        $this->configFile = $this->middlewareConfig([FreeShippingNudge::class, TraceOutbox::class]);
        $factory = new AgentFactory(HarnessConfig::fromEnvironment(), $this->configFile);

        try {
            $factory->turnRunner($this->pdo, $sessionId, null);
            $this->fail('a harness without its required middleware must not be built');
        } catch (LogicException $error) {
            $this->assertStringStartsWith('Required middleware missing: ', $error->getMessage());
        }
        $this->assertSame([], $this->column('SELECT role FROM chat_messages WHERE session_id = ?', $sessionId));
    }

    public function testTemplateModeEmitsSingleDraftDelta(): void
    {
        $this->setEnv('CHATBOT_TEMPLATE_RESPONSE', '1');
        $userId = $this->createUserWithCart();
        $sessionId = $this->createSession($userId);
        $advice = (new FreeShippingService($this->pdo, FreeShippingPolicy::fromEnvironment()))->suggest($userId, ['limit' => 1]);
        $this->assertSame(30000, (int)$advice['gap']);
        $deltas = [];

        $result = (new ChatbotService($this->pdo, $sessionId, $userId))->respondStreaming(
            'tìm áo thun',
            static function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            }
        );
        $this->traceIds[] = (string)$result['trace_id'];

        $nudge = ' ' . FreeShippingNudge::message($advice);
        $this->assertCount(2, $deltas);
        $this->assertSame($nudge, $deltas[1]);
        $this->assertSame($deltas[0] . $nudge, $result['message']);
        $this->assertTrue($result['freeship_nudge']);
        $this->assertSame('template', $result['latency']['streaming']);
    }

    private function respond(ChatbotService $service, string $message): array
    {
        $result = $service->respond($message);
        $this->traceIds[] = (string)$result['trace_id'];
        return $result;
    }

    private function createUserWithCart(): int
    {
        $tag = bin2hex(random_bytes(6));
        $this->pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')")
            ->execute(["assembly_$tag", "assembly_$tag@example.test", password_hash($tag, PASSWORD_DEFAULT)]);
        $this->userId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO cart (user_id, product_id, quantity, size) VALUES (?, ?, 1, ?)')
            ->execute([$this->userId, self::CART_PRODUCT_ID, 'M']);
        return $this->userId;
    }

    private function createSession(?int $userId): int
    {
        $this->pdo->prepare("INSERT INTO chat_sessions (user_id, session_token, status) VALUES (?, ?, 'active')")
            ->execute([$userId, bin2hex(random_bytes(16))]);
        $this->sessionId = (int)$this->pdo->lastInsertId();
        return $this->sessionId;
    }

    /** @param list<class-string> $middleware */
    private function middlewareConfig(array $middleware): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'harness');
        $list = implode(', ', array_map(static fn (string $class): string => $class . '::class', $middleware));
        file_put_contents($path, "<?php\nreturn ['middleware' => [$list]];\n");
        return $path;
    }

    /** @return list<mixed> */
    private function column(string $sql, int $id): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
