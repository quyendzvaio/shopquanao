<?php

/**
 * Writes the final turn result (after every earlier afterTurn) to the Langfuse
 * trace outbox. Only a sanitized local row is written; the separate publisher
 * owns all HTTP, retries and failure handling. Optional afterTurn middleware.
 */
final class TraceOutbox extends BaseMiddleware
{
    public function __construct(private LangfuseTraceOutbox $outbox)
    {
    }

    public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult
    {
        $this->outbox->enqueueSafely(
            (string)$result->get('trace_id', ''),
            $turn->message,
            $result->toArray(),
            $turn->userId,
            $turn->sessionId
        );
        return $result;
    }
}
