<?php

/** Pure, persistence-neutral state machine for the two-turn proactive offer. */
final class ProactiveStylingStateMachine
{
    /** @param array<string,mixed>|null $state */
    public function onCartItemAdded(array $state, int $productId, ?int $variantId, string $eventId): array
    {
        return array_merge($state, [
            'pending_product_id' => $productId,
            'pending_variant_id' => $variantId,
            'remaining_user_turns' => 2,
            'source_event_id' => $eventId,
            'eligible' => true,
            'suggested_anchor_product_id' => null,
        ]);
    }

    /** @return array{state:array<string,mixed>, action:string} */
    public function onUserTurn(array $state, bool $suitable, bool $hasProducts): array
    {
        if (($state['pending_product_id'] ?? null) === null || ($state['eligible'] ?? false) !== true) {
            return ['state' => $state, 'action' => 'silent'];
        }
        $remaining = (int) ($state['remaining_user_turns'] ?? 0);
        if ($remaining > 0) $state['remaining_user_turns'] = $remaining - 1;
        if ($state['remaining_user_turns'] > 0) {
            return ['state' => $state, 'action' => 'silent'];
        }
        if (!$suitable) return ['state' => $state, 'action' => 'silent'];
        if (!$hasProducts) {
            return ['state' => $state, 'action' => 'silent'];
        }
        $state['suggested_anchor_product_id'] = (int) $state['pending_product_id'];
        $state['eligible'] = false;
        return ['state' => $state, 'action' => 'suggest'];
    }
}
