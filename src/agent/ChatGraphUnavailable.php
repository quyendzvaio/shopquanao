<?php

/**
 * chat-graph could not answer this turn: unreachable, too slow, a non-200
 * status, or a body that is not the NDJSON/JSON contract. ChatbotService turns
 * it into the busy answer; $category is the log label.
 */
final class ChatGraphUnavailable extends RuntimeException
{
    public function __construct(public readonly string $category, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
