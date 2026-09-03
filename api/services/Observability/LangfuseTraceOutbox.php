<?php

/**
 * Stores sanitized completed chatbot traces locally for asynchronous export.
 *
 * This class intentionally performs no network I/O. Observability must never
 * become a dependency of the customer response path.
 */
final class LangfuseTraceOutbox
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function isConfigured(): bool
    {
        return filter_var(getenv('LANGFUSE_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN)
            && trim((string) (getenv('LANGFUSE_INGESTION_URL') ?: getenv('LANGFUSE_BASE_URL'))) !== ''
            && trim((string) getenv('LANGFUSE_PUBLIC_KEY')) !== ''
            && trim((string) getenv('LANGFUSE_SECRET_KEY')) !== '';
    }

    /**
     * Best-effort persistence only. A database/migration error is deliberately
     * suppressed so tracing cannot fail a chatbot response or token stream.
     */
    public function enqueueSafely(string $traceId, string $message, array $result, ?int $userId, int $sessionId): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        try {
            if (!preg_match('/^[a-f0-9]{32}$/', $traceId)) {
                throw new InvalidArgumentException('Trace id is not a valid 16-byte OTLP trace id');
            }

            $payload = $this->buildPayload($traceId, $message, $result, $userId, $sessionId);
            $eventId = bin2hex(random_bytes(16));
            $stmt = $this->pdo->prepare(
                "INSERT INTO langfuse_trace_outbox (event_id, trace_id, payload, status, available_at)
                 VALUES (?, ?, ?, 'pending', CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                $eventId,
                $traceId,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return true;
        } catch (Throwable $error) {
            error_log(json_encode([
                'operation' => 'langfuse_trace_outbox_enqueue',
                'success' => false,
                'failure_category' => 'suppressed_observability_failure',
            ], JSON_UNESCAPED_SLASHES));
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function buildPayload(string $traceId, string $message, array $result, ?int $userId, int $sessionId): array
    {
        $routing = is_array($result['latency']['routing'] ?? null) ? $result['latency']['routing'] : [];
        $latency = is_array($result['latency'] ?? null) ? $result['latency'] : [];
        $products = [];
        foreach (($result['products'] ?? $result['cards'] ?? []) as $product) {
            if (is_array($product) && isset($product['id']) && is_numeric($product['id'])) {
                $products[] = (int) $product['id'];
            }
        }

        $includeContent = filter_var(getenv('LANGFUSE_TRACE_CONTENT') ?: false, FILTER_VALIDATE_BOOLEAN);
        $input = [
            'message_chars' => mb_strlen($message),
        ];
        $output = [
            'response_type' => $this->boundedString($result['response_type'] ?? 'unknown', 80),
            'primary_intent' => $this->boundedString($result['primary_intent'] ?? 'unknown', 120),
            'private_product_ids' => array_values(array_unique(array_slice($products, 0, 20))),
            'private_product_count' => count($products),
            'proactive_styling' => (bool) ($result['proactive_styling'] ?? false),
        ];
        if ($includeContent) {
            $input['message'] = $this->boundedString($message, 2000);
            $output['answer'] = $this->boundedString($result['answer'] ?? $result['message'] ?? '', 4000);
        }

        return [
            'trace_id' => $traceId,
            'name' => 'shopquanao.chatbot.request',
            'observed_at_unix_nano' => (string) ((int) floor(microtime(true) * 1000000000)),
            'duration_ms' => max(0, (int) ($latency['total_ms'] ?? 0)),
            'input' => $input,
            'output' => $output,
            'metadata' => [
                'project' => $this->boundedString(getenv('LANGFUSE_PROJECT') ?: 'shopquanao', 120),
                'session_id' => (string) $sessionId,
                'user_id' => $userId,
                'selected_tools' => $this->stringList($routing['selected_tools'] ?? []),
                'loop_count' => max(0, (int) ($routing['loop_count'] ?? $latency['loop_count'] ?? 0)),
                'decision' => $this->boundedString(is_array($routing['decision'] ?? null) ? ($routing['decision']['action'] ?? '') : '', 80),
                'latency_ms' => $this->numericLatency($latency),
            ],
        ];
    }

    private function boundedString(mixed $value, int $limit): string
    {
        return mb_substr(trim((string) $value), 0, $limit);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $item = $this->boundedString($item, 120);
            if ($item !== '') {
                $items[] = $item;
            }
        }
        return array_values(array_unique(array_slice($items, 0, 12)));
    }

    /** @return array<string,int> */
    private function numericLatency(array $latency): array
    {
        $safe = [];
        foreach ($latency as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_ms') && is_numeric($value)) {
                $safe[$key] = max(0, (int) $value);
            }
        }
        return $safe;
    }
}
