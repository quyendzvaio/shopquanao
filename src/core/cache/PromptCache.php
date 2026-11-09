<?php

/**
 * Turn-level cache for guest chat turns (prompt-cache plan 2.3).
 *
 * Backed by the shared Cache (Redis with a file fallback). A cached turn is a
 * whole ChatbotService::turn() result for a guest message; it is stored under a
 * key that hashes the message, the history window and the memory slice, so a
 * changed conversation state or memory is a different key. TTL comes from the
 * PROMPT_CACHE_TTL env (default 1800 s = 30 min).
 *
 * Only guests are cached: a logged-in turn can name orders, tickets or carts,
 * and its answer may legitimately change from turn to turn. Cache failures are
 * swallowed by the caller: caching must never break or delay a turn.
 */
final class PromptCache
{
    private const KEY_PREFIX = 'prompt_cache:';

    private mixed $backend;

    public function __construct(mixed $backend = null)
    {
        $this->backend = $backend ?? new Cache();
    }

    /** The instance the chat turn uses (the shared Cache backend). */
    public static function forTurn(): self
    {
        return new self();
    }

    /**
     * The cache key of a guest turn: message + history window + memory slice.
     *
     * @param array<int|string,mixed> $history ChatHistoryWindow::fromRows output
     * @param string $summary   The session summary as sent to the graph
     * @param array<string,mixed> $slots The session slots as sent to the graph
     */
    public function keyFor(string $message, array $history, string $summary, array $slots): string
    {
        $payload = json_encode([
            'v' => 1, // bump to invalidate every stored answer after a key-shape change
            'message' => $message,
            'history' => $history,
            'summary' => $summary,
            'slots' => $slots,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return self::KEY_PREFIX . hash('sha256', (string) $payload);
    }

    /** The cached turn result, or null on a miss. */
    public function get(string $key): ?array
    {
        $value = $this->backend->get($key);
        return is_array($value) ? $value : null;
    }

    /** False when PROMPT_CACHE_TTL=0: the kill switch for an incident or a test. */
    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    /** Stores a turn result for PROMPT_CACHE_TTL seconds (default 1800). */
    public function set(string $key, array $turn): void
    {
        $ttl = $this->ttl();
        if ($ttl <= 0) {
            return;
        }
        $this->backend->set($key, $turn, $ttl);
    }

    private function ttl(): int
    {
        $ttlEnv = getenv('PROMPT_CACHE_TTL');
        return $ttlEnv !== false && $ttlEnv !== '' ? (int) $ttlEnv : 1800;
    }
}
