<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TraceOutboxTest extends TestCase
{
    private const ENV = [
        'LANGFUSE_ENABLED' => 'true',
        'LANGFUSE_BASE_URL' => 'http://langfuse.test',
        'LANGFUSE_PUBLIC_KEY' => 'pk-test',
        'LANGFUSE_SECRET_KEY' => 'sk-test',
    ];

    /** @var array<string,string|false> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $key => $value) {
            $this->previousEnv[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testEnqueuesResult(): void
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec("CREATE TABLE langfuse_trace_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL UNIQUE,
            trace_id TEXT NOT NULL, payload TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
            available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $traceId = str_repeat('ab', 16);
        $result = TurnResult::fromArray([
            'message' => 'Mình tìm thấy áo mã 53.',
            'answer' => 'Mình tìm thấy áo mã 53.',
            'products' => [['id' => 53], ['id' => 58]],
            'response_type' => 'final_answer',
            'primary_intent' => 'product_search',
            'trace_id' => $traceId,
            'latency' => ['total_ms' => 12],
        ]);

        $out = (new TraceOutbox(new LangfuseTraceOutbox($pdo)))
            ->afterTurn(new TurnContext('áo len', 9, 1, [], null), $result);

        $this->assertSame($result, $out, 'the turn result passes through unchanged');
        $row = $pdo->query('SELECT trace_id, payload, status FROM langfuse_trace_outbox')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $row);
        $this->assertSame($traceId, $row[0]['trace_id']);
        $this->assertSame('pending', $row[0]['status']);
        $payload = json_decode((string)$row[0]['payload'], true);
        $this->assertSame($traceId, $payload['trace_id']);
        $this->assertSame(['message_chars' => 6], $payload['input']);
        $this->assertSame('product_search', $payload['output']['primary_intent']);
        $this->assertSame([53, 58], $payload['output']['private_product_ids']);
        $this->assertSame('9', $payload['metadata']['session_id']);
        $this->assertSame(1, $payload['metadata']['user_id']);
        $this->assertSame(12, $payload['duration_ms']);
    }

    public function testATurnWithoutTraceIdEnqueuesNothingAndDoesNotFail(): void
    {
        $pdo = SqliteShop::pdo();
        $result = TurnResult::fromArray(['message' => 'x', 'response_type' => 'final_answer']);

        $log = tempnam(sys_get_temp_dir(), 'trace');
        $previous = ini_set('error_log', $log);
        try {
            $out = (new TraceOutbox(new LangfuseTraceOutbox($pdo)))
                ->afterTurn(new TurnContext('x', 9, null, [], null), $result);
        } finally {
            ini_set('error_log', (string)$previous);
        }
        $logged = (string)file_get_contents($log);
        unlink($log);

        $this->assertSame($result, $out);
        $this->assertStringContainsString('"operation":"langfuse_trace_outbox_enqueue"', $logged);
    }
}
