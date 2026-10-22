<?php

/**
 * Refuses a checkout/cart/payment request before any intent, tool or evidence
 * work runs. The pipeline's own unsupported_checkout branch stays as the
 * second line of defence.
 */
final class CheckoutGuardrail extends BaseMiddleware
{
    public const REQUIRED = true;

    public function __construct(private ResponseGenerator $responses)
    {
    }

    public function beforeTurn(TurnContext $turn): ?TurnResult
    {
        if (!CheckoutDetector::matches($turn->message)) {
            return null;
        }
        $refusal = $this->responses->checkoutRefusal($turn->message);
        return TurnResult::fromArray([
            'message' => $refusal,
            'answer' => $refusal,
            'products' => [],
            'cards' => [],
            'knowledge_sources' => [],
            'response_type' => 'final_answer',
            'primary_intent' => 'unsupported_checkout',
            'secondary_intents' => [],
            'requested_fields' => [],
            'missing_slots' => [],
            // OTLP trace IDs are exactly 16 bytes (32 lowercase hex chars).
            'trace_id' => bin2hex(random_bytes(16)),
            'latency' => ['pipeline' => 'deterministic_hybrid_pipeline', 'blocked_by' => 'CheckoutGuardrail', 'total_ms' => 0],
        ]);
    }
}
