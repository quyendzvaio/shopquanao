<?php

final class ProactiveStylingStateStore
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string,mixed> */
    public function get(int $userId, string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM proactive_styling_state WHERE user_id = ? AND session_id = ?');
        $stmt->execute([$userId, $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return [];
        foreach (['pending_product_id', 'pending_variant_id', 'remaining_user_turns', 'suggested_anchor_product_id', 'state_version', 'retry_count'] as $key) {
            if ($row[$key] !== null) $row[$key] = (int) $row[$key];
        }
        $row['eligible'] = (bool) $row['eligible'];
        return $row;
    }

    /** @param array<string,mixed> $state */
    public function put(int $userId, string $sessionId, array $state): void
    {
        $sql = 'INSERT INTO proactive_styling_state
            (user_id, session_id, pending_product_id, pending_variant_id, remaining_user_turns, source_event_id, eligible, suggested_anchor_product_id, state_version, status, failure_reason, retry_count, last_attempt_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE pending_product_id=VALUES(pending_product_id), pending_variant_id=VALUES(pending_variant_id),
              remaining_user_turns=VALUES(remaining_user_turns), source_event_id=VALUES(source_event_id), eligible=VALUES(eligible),
              suggested_anchor_product_id=VALUES(suggested_anchor_product_id), state_version=VALUES(state_version), status=VALUES(status),
              failure_reason=VALUES(failure_reason), retry_count=VALUES(retry_count), last_attempt_at=VALUES(last_attempt_at), updated_at=CURRENT_TIMESTAMP';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(user_id, session_id) DO UPDATE SET', $sql);
            $sql = str_replace('VALUES(pending_product_id)', 'excluded.pending_product_id', $sql);
            $sql = str_replace('VALUES(pending_variant_id)', 'excluded.pending_variant_id', $sql);
            $sql = str_replace('VALUES(remaining_user_turns)', 'excluded.remaining_user_turns', $sql);
            $sql = str_replace('VALUES(source_event_id)', 'excluded.source_event_id', $sql);
            $sql = str_replace('VALUES(eligible)', 'excluded.eligible', $sql);
            $sql = str_replace('VALUES(suggested_anchor_product_id)', 'excluded.suggested_anchor_product_id', $sql);
            $sql = str_replace('VALUES(state_version)', 'excluded.state_version', $sql);
            $sql = str_replace('VALUES(status)', 'excluded.status', $sql);
            $sql = str_replace('VALUES(failure_reason)', 'excluded.failure_reason', $sql);
            $sql = str_replace('VALUES(retry_count)', 'excluded.retry_count', $sql);
            $sql = str_replace('VALUES(last_attempt_at)', 'excluded.last_attempt_at', $sql);
        }
        $this->pdo->prepare($sql)->execute([
            $userId, $sessionId, $state['pending_product_id'] ?? null, $state['pending_variant_id'] ?? null,
            (int) ($state['remaining_user_turns'] ?? 0), $state['source_event_id'] ?? null,
            !empty($state['eligible']) ? 1 : 0, $state['suggested_anchor_product_id'] ?? null,
            (int) ($state['state_version'] ?? 0), (string) ($state['status'] ?? 'not_armed'),
            $state['failure_reason'] ?? null, (int) ($state['retry_count'] ?? 0), $state['last_attempt_at'] ?? null,
        ]);
    }
}
