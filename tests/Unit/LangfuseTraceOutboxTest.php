<?php

use PHPUnit\Framework\TestCase;

final class LangfuseTraceOutboxTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE langfuse_trace_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id TEXT NOT NULL UNIQUE,
                trace_id TEXT NOT NULL,
                payload TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                published_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        putenv('LANGFUSE_ENABLED=true');
        putenv('LANGFUSE_BASE_URL=http://langfuse.test:3000');
        putenv('LANGFUSE_PUBLIC_KEY=pk-lf-test');
        putenv('LANGFUSE_SECRET_KEY=sk-lf-test');
        putenv('LANGFUSE_TRACE_CONTENT=false');
        putenv('LANGFUSE_PUBLISH_MAX_ATTEMPTS=3');
    }

    protected function tearDown(): void
    {
        foreach ([
            'LANGFUSE_ENABLED', 'LANGFUSE_BASE_URL', 'LANGFUSE_PUBLIC_KEY',
            'LANGFUSE_SECRET_KEY', 'LANGFUSE_TRACE_CONTENT',
            'LANGFUSE_PUBLISH_MAX_ATTEMPTS',
        ] as $key) {
            putenv($key);
        }
    }

    public function testItQueuesSanitizedTraceWithoutNetworkIo(): void
    {
        $outbox = new LangfuseTraceOutbox($this->pdo);
        $traceId = str_repeat('a', 32);
        $queued = $outbox->enqueueSafely($traceId, 'Thông tin riêng tư của khách', [
            'response_type' => 'product_search',
            'primary_intent' => 'product_search',
            'answer' => 'Câu trả lời riêng tư',
            'products' => [['id' => 42], ['id' => 7]],
            'latency' => [
                'total_ms' => 180,
                'evidence_execution_ms' => 80,
                'routing' => ['selected_tools' => ['search_products'], 'loop_count' => 1, 'decision' => ['action' => 'return']],
            ],
        ], 9, 17);

        self::assertTrue($queued);
        $row = $this->pdo->query('SELECT trace_id, payload, status FROM langfuse_trace_outbox')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($traceId, $row['trace_id']);
        self::assertSame('pending', $row['status']);
        self::assertStringNotContainsString('Thông tin riêng tư', $row['payload']);
        self::assertStringNotContainsString('Câu trả lời riêng tư', $row['payload']);
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([42, 7], $payload['output']['private_product_ids']);
        self::assertSame(['search_products'], $payload['metadata']['selected_tools']);
    }

    public function testDisabledTracingDoesNotWriteOrAffectTheAgentPath(): void
    {
        putenv('LANGFUSE_ENABLED=false');
        $queued = (new LangfuseTraceOutbox($this->pdo))->enqueueSafely(
            str_repeat('d', 32),
            'áo trắng',
            ['latency' => ['total_ms' => 1]],
            null,
            3
        );

        self::assertFalse($queued);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM langfuse_trace_outbox')->fetchColumn());
    }

    public function testWorkerPublishesOtlpPayloadOutsideRequestPath(): void
    {
        $outbox = new LangfuseTraceOutbox($this->pdo);
        self::assertTrue($outbox->enqueueSafely(str_repeat('b', 32), 'áo trắng', [
            'response_type' => 'product_search',
            'primary_intent' => 'product_search',
            'products' => [['id' => 11]],
            'latency' => ['total_ms' => 25, 'generation_ms' => 5],
        ], null, 3));

        $calls = [];
        $publisher = new LangfuseOtlpTracePublisher($this->pdo, static function (string $endpoint, string $body) use (&$calls): int {
            $calls[] = ['endpoint' => $endpoint, 'body' => $body];
            return 202;
        });
        $report = $publisher->runBatch();

        self::assertSame(['processed' => 1, 'published' => 1, 'failed' => 0, 'disabled' => false], $report);
        self::assertCount(1, $calls);
        self::assertSame('http://langfuse.test:3000/api/public/otel/v1/traces', $calls[0]['endpoint']);
        $otlp = json_decode($calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $spans = $otlp['resourceSpans'][0]['scopeSpans'][0]['spans'];
        self::assertSame('shopquanao.chatbot.request', $spans[0]['name']);
        self::assertSame('span', $spans[0]['attributes'][0]['value']['stringValue']);
        self::assertSame('published', $this->pdo->query('SELECT status FROM langfuse_trace_outbox')->fetchColumn());
    }

    public function testRetryableExporterFailureDoesNotThrowAndLeavesTracePending(): void
    {
        $outbox = new LangfuseTraceOutbox($this->pdo);
        self::assertTrue($outbox->enqueueSafely(str_repeat('c', 32), 'áo trắng', [
            'latency' => ['total_ms' => 1],
        ], null, 3));

        $publisher = new LangfuseOtlpTracePublisher($this->pdo, static fn(): int => 503);
        $report = $publisher->runBatch();

        self::assertSame(1, $report['failed']);
        $row = $this->pdo->query('SELECT status, attempts FROM langfuse_trace_outbox')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
    }
}
