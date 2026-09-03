<?php

/**
 * Dedicated worker-side OTLP/HTTP exporter. It is never constructed from the
 * request/streaming path, so a timeout or outage cannot delay an agent reply.
 */
final class LangfuseOtlpTracePublisher
{
    /** @var null|callable(string,string,array):int */
    private $post;

    public function __construct(private PDO $pdo, ?callable $post = null)
    {
        $this->post = $post;
    }

    /** @return array{processed:int,published:int,failed:int,disabled:bool} */
    public function runBatch(int $limit = 50): array
    {
        $report = ['processed' => 0, 'published' => 0, 'failed' => 0, 'disabled' => false];
        if (!LangfuseTraceOutbox::isConfigured()) {
            $report['disabled'] = true;
            return $report;
        }

        $limit = max(1, min(200, $limit));
        $rows = $this->pdo->query(
            "SELECT id, payload, attempts FROM langfuse_trace_outbox
             WHERE status = 'pending' AND available_at <= CURRENT_TIMESTAMP
             ORDER BY id ASC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $report['processed']++;
            try {
                $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new UnexpectedValueException('Invalid stored Langfuse trace payload');
                }
                $status = $this->postTrace($this->otlpEndpoint(), $this->buildOtlpPayload($payload));
                if ($status < 200 || $status >= 300) {
                    throw new RuntimeException('Langfuse OTLP rejected trace with HTTP ' . $status);
                }
                $this->pdo->prepare(
                    "UPDATE langfuse_trace_outbox
                     SET status = 'published', published_at = CURRENT_TIMESTAMP, last_error = NULL
                     WHERE id = ? AND status = 'pending'"
                )->execute([(int) $row['id']]);
                $report['published']++;
            } catch (Throwable $error) {
                $attempts = (int) ($row['attempts'] ?? 0) + 1;
                $maxAttempts = max(1, min(20, (int) (getenv('LANGFUSE_PUBLISH_MAX_ATTEMPTS') ?: 8)));
                $retryable = $this->isRetryable($error);
                $status = ($retryable && $attempts < $maxAttempts) ? 'pending' : 'failed';
                $delay = min(300, 2 ** min(8, $attempts));
                $this->pdo->prepare(
                    "UPDATE langfuse_trace_outbox
                     SET status = ?, attempts = ?, last_error = ?, available_at = ?
                     WHERE id = ? AND status = 'pending'"
                )->execute([
                    $status,
                    $attempts,
                    mb_substr($this->safeError($error), 0, 500),
                    gmdate('Y-m-d H:i:s', time() + $delay),
                    (int) $row['id'],
                ]);
                $report['failed']++;
                error_log(json_encode([
                    'operation' => 'langfuse_trace_export',
                    'success' => false,
                    'failure_category' => $retryable ? 'retryable_export_failure' : 'permanent_export_failure',
                ], JSON_UNESCAPED_SLASHES));
            }
        }

        return $report;
    }

    /** @return array<string,mixed> */
    public function buildOtlpPayload(array $trace): array
    {
        $endNs = (string) ($trace['observed_at_unix_nano'] ?? '0');
        $durationNs = max(0, (int) ($trace['duration_ms'] ?? 0)) * 1000000;
        $startNs = (string) max(0, (int) $endNs - $durationNs);
        $traceId = (string) ($trace['trace_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $traceId)) {
            throw new InvalidArgumentException('Stored trace id is invalid');
        }

        $rootSpanId = bin2hex(random_bytes(8));
        $root = $this->span(
            $traceId,
            $rootSpanId,
            null,
            (string) ($trace['name'] ?? 'shopquanao.chatbot.request'),
            $startNs,
            $endNs,
            [
                'langfuse.observation.type' => 'span',
                'langfuse.observation.input' => $this->json($trace['input'] ?? []),
                'langfuse.observation.output' => $this->json($trace['output'] ?? []),
                'langfuse.observation.metadata.project' => (string) (($trace['metadata']['project'] ?? 'shopquanao')),
                'langfuse.observation.metadata.session_id' => (string) (($trace['metadata']['session_id'] ?? '')),
                'langfuse.observation.metadata.user_id' => (string) (($trace['metadata']['user_id'] ?? '')),
                'langfuse.observation.metadata.selected_tools' => $this->json($trace['metadata']['selected_tools'] ?? []),
                'langfuse.observation.metadata.loop_count' => (int) (($trace['metadata']['loop_count'] ?? 0)),
                'langfuse.observation.metadata.decision' => (string) (($trace['metadata']['decision'] ?? '')),
            ]
        );

        $spans = [$root];
        $cursor = (int) $startNs;
        foreach (($trace['metadata']['latency_ms'] ?? []) as $stage => $durationMs) {
            if (!is_string($stage) || !is_numeric($durationMs)) {
                continue;
            }
            $duration = max(0, (int) $durationMs) * 1000000;
            $stageStart = (string) min((int) $endNs, $cursor);
            $stageEnd = (string) min((int) $endNs, $cursor + $duration);
            $spans[] = $this->span($traceId, bin2hex(random_bytes(8)), $rootSpanId, 'agent.' . $stage, $stageStart, $stageEnd, [
                'langfuse.observation.type' => 'span',
                'langfuse.observation.metadata.duration_ms' => max(0, (int) $durationMs),
            ]);
            $cursor += $duration;
        }

        return [
            'resourceSpans' => [[
                'resource' => ['attributes' => [
                    $this->attribute('service.name', 'shopquanao-agent'),
                    $this->attribute('service.version', trim((string) (getenv('APP_VERSION') ?: 'unknown'))),
                    $this->attribute('deployment.environment', trim((string) (getenv('APP_ENV') ?: 'production'))),
                ]],
                'scopeSpans' => [[
                    'scope' => ['name' => 'shopquanao.langfuse'],
                    'spans' => $spans,
                ]],
            ]],
        ];
    }

    private function otlpEndpoint(): string
    {
        $baseUrl = (string) (getenv('LANGFUSE_INGESTION_URL') ?: getenv('LANGFUSE_BASE_URL'));
        return rtrim($baseUrl, '/') . '/api/public/otel/v1/traces';
    }

    /** @param array<string,mixed> $payload */
    private function postTrace(string $endpoint, array $payload): int
    {
        if ($this->post !== null) {
            return (int) ($this->post)($endpoint, $this->json($payload), []);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl extension is unavailable');
        }

        $credentials = base64_encode((string) getenv('LANGFUSE_PUBLIC_KEY') . ':' . (string) getenv('LANGFUSE_SECRET_KEY'));
        $timeout = max(250, min(10000, (int) (getenv('LANGFUSE_PUBLISH_TIMEOUT_MS') ?: 1500)));
        $connectTimeout = max(100, min($timeout, (int) (getenv('LANGFUSE_PUBLISH_CONNECT_TIMEOUT_MS') ?: 500)));
        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->json($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . $credentials,
                'x-langfuse-ingestion-version: 4',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeout,
            CURLOPT_TIMEOUT_MS => $timeout,
        ]);
        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Langfuse transport failure: ' . ($error === '' ? 'unknown' : $error));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return $status;
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function span(string $traceId, string $spanId, ?string $parentSpanId, string $name, string $startNs, string $endNs, array $attributes): array
    {
        $span = [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'name' => $name,
            'kind' => 1,
            'startTimeUnixNano' => $startNs,
            'endTimeUnixNano' => $endNs,
            'attributes' => [],
        ];
        if ($parentSpanId !== null) {
            $span['parentSpanId'] = $parentSpanId;
        }
        foreach ($attributes as $key => $value) {
            $span['attributes'][] = $this->attribute($key, $value);
        }
        return $span;
    }

    /** @return array{key:string,value:array<string,mixed>} */
    private function attribute(string $key, mixed $value): array
    {
        if (is_bool($value)) {
            return ['key' => $key, 'value' => ['boolValue' => $value]];
        }
        if (is_int($value)) {
            return ['key' => $key, 'value' => ['intValue' => (string) $value]];
        }
        if (is_float($value)) {
            return ['key' => $key, 'value' => ['doubleValue' => $value]];
        }
        return ['key' => $key, 'value' => ['stringValue' => (string) $value]];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isRetryable(Throwable $error): bool
    {
        if (preg_match('/HTTP (400|401|403|404|422)/', $error->getMessage())) {
            return false;
        }
        return true;
    }

    private function safeError(Throwable $error): string
    {
        return preg_replace('/(?:pk|sk)-lf-[A-Za-z0-9_-]+/', '[redacted]', $error->getMessage()) ?: 'Langfuse export failed';
    }
}
