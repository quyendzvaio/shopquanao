<?php

/**
 * Reads and writes chat_session_memory (per-session summary and slots) and
 * user_long_term_memory (per logged-in user). The upserts keep their sqlite /
 * mysql driver branches; callers own the error handling.
 */
final class ChatMemoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Runtime CREATE TABLE IF NOT EXISTS for the session memory table and, when asked, the long-term table. */
    public function ensureTables(bool $withLongTerm = true): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS chat_session_memory (
                session_id int NOT NULL PRIMARY KEY,
                summary text DEFAULT NULL,
                slots longtext DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        if (!$withLongTerm) {
            return;
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_long_term_memory (
                user_id int NOT NULL PRIMARY KEY,
                preferences longtext DEFAULT NULL,
                stable_facts longtext DEFAULT NULL,
                important_events longtext DEFAULT NULL,
                feedback longtext DEFAULT NULL,
                purchase_history longtext DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
    }

    /** @return array<string,mixed>|null summary and slots of the session, or null without a memory row */
    public function sessionMemory(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT summary, slots FROM chat_session_memory WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null the five JSON columns of the user, or null without a row */
    public function longTerm(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT preferences, stable_facts, important_events, feedback, purchase_history FROM user_long_term_memory WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Upserts the session summary and slots (sqlite ON CONFLICT, otherwise ON DUPLICATE KEY). */
    public function saveSession(int $sessionId, string $summary, string|false $slotsJson): void
    {
        if ($this->driverName() === 'sqlite') {
            $stmt = $this->pdo->prepare("INSERT INTO chat_session_memory (session_id, summary, slots, updated_at)
                    VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(session_id) DO UPDATE SET summary = excluded.summary, slots = excluded.slots, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$sessionId, $summary, $slotsJson]);
            return;
        }

        $stmt = $this->pdo->prepare("INSERT INTO chat_session_memory (session_id, summary, slots, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE summary = VALUES(summary), slots = VALUES(slots), updated_at = NOW()");
        $stmt->execute([$sessionId, $summary, $slotsJson]);
    }

    /**
     * Upserts the user's long-term memory (sqlite ON CONFLICT, otherwise ON DUPLICATE KEY).
     *
     * @param list<string|false> $payload preferences, stable_facts, important_events, feedback, purchase_history (JSON)
     */
    public function saveLongTerm(int $userId, array $payload): void
    {
        if ($this->driverName() === 'sqlite') {
            $stmt = $this->pdo->prepare("INSERT INTO user_long_term_memory
                    (user_id, preferences, stable_facts, important_events, feedback, purchase_history, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(user_id) DO UPDATE SET preferences = excluded.preferences, stable_facts = excluded.stable_facts,
                        important_events = excluded.important_events, feedback = excluded.feedback,
                        purchase_history = excluded.purchase_history, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute(array_merge([$userId], $payload));
            return;
        }

        $stmt = $this->pdo->prepare("INSERT INTO user_long_term_memory
                (user_id, preferences, stable_facts, important_events, feedback, purchase_history, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE preferences = VALUES(preferences), stable_facts = VALUES(stable_facts),
                    important_events = VALUES(important_events), feedback = VALUES(feedback),
                    purchase_history = VALUES(purchase_history), updated_at = NOW()");
        $stmt->execute(array_merge([$userId], $payload));
    }

    /** @return string|null|false the slots column; null when it is NULL, false when the session has no memory row */
    public function slotsJson(int $sessionId): string|null|false
    {
        $stmt = $this->pdo->prepare('SELECT slots FROM chat_session_memory WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        return $stmt->fetchColumn();
    }

    public function updateSlots(int $sessionId, string|false $json): void
    {
        $this->pdo->prepare('UPDATE chat_session_memory SET slots = ? WHERE session_id = ?')->execute([$json, $sessionId]);
    }

    public function insertSlots(int $sessionId, string|false $json): void
    {
        $this->pdo->prepare("INSERT INTO chat_session_memory (session_id, summary, slots) VALUES (?, '', ?)")->execute([$sessionId, $json]);
    }

    private function driverName(): string
    {
        try {
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            return 'mysql';
        }
    }
}
