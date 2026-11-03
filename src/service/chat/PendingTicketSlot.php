<?php

/**
 * The chat-session memory slot that holds a drafted, not yet confirmed support
 * ticket. ChatbotService writes and consumes it; ChatHistoryService only asks
 * whether a draft is still waiting. Never sent to chat-graph.
 */
final class PendingTicketSlot
{
    public const KEY = 'pending_ticket';
    /** Seconds a draft waits for the customer's yes or no. */
    public const TTL = 900;

    /** True when $stored is this user's draft and it has not expired at $now. */
    public static function isLive(mixed $stored, ?int $userId, int $now): bool
    {
        if ($userId === null || $userId <= 0 || !is_string($stored) || $stored === '') {
            return false;
        }
        $draft = json_decode($stored, true);

        return is_array($draft)
            && ($draft['user_id'] ?? null) === $userId
            && is_int($draft['expires_at'] ?? null)
            && $draft['expires_at'] > $now;
    }
}
