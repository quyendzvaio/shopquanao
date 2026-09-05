<?php

/** Pure, persistence-neutral state machine for the two-turn proactive offer. */
final class ProactiveStylingStateMachine
{
    /** @param array<string,mixed>|null $state */
    public function onCartItemAdded(array $state, int $productId, ?int $variantId, string $eventId, int $stateVersion = 0): array
    {
        if (($state['source_event_id'] ?? null) === $eventId) {
            return $state;
        }
        if ($stateVersion > 0 && (int) ($state['state_version'] ?? 0) >= $stateVersion) {
            return $state;
        }

        return array_merge($state, [
            'pending_product_id' => $productId,
            'pending_variant_id' => $variantId,
            'remaining_user_turns' => 2,
            'source_event_id' => $eventId,
            'state_version' => $stateVersion,
            'eligible' => true,
            'suggested_anchor_product_id' => null,
            'status' => 'waiting_for_turn',
            'failure_reason' => null,
        ]);
    }

    /** @return array{state:array<string,mixed>, action:string} */
    public function onUserTurn(array $state, bool $suitable, bool $hasProducts): array
    {
        if (($state['pending_product_id'] ?? null) === null || ($state['eligible'] ?? false) !== true) {
            $state['status'] = 'not_armed';
            return ['state' => $state, 'action' => 'silent'];
        }
        $remaining = (int) ($state['remaining_user_turns'] ?? 0);
        if ($remaining > 0) $state['remaining_user_turns'] = $remaining - 1;
        if ($state['remaining_user_turns'] > 0) {
            $state['status'] = 'waiting_for_turn';
            return ['state' => $state, 'action' => 'silent'];
        }
        if (!$suitable) {
            $state['status'] = 'waiting_for_suitable_context';
            return ['state' => $state, 'action' => 'silent'];
        }
        if (!$hasProducts) {
            $state['status'] = 'no_private_catalog_match';
            return ['state' => $state, 'action' => 'silent'];
        }
        $state['suggested_anchor_product_id'] = (int) $state['pending_product_id'];
        $state['eligible'] = false;
        $state['status'] = 'shown';
        return ['state' => $state, 'action' => 'suggest'];
    }
}
