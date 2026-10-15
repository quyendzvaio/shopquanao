<?php

/**
 * Turn tier gating (performance tiers).
 *
 * L0: fully deterministic turn — known intent, no execution-affecting
 *     unresolved spans, no unresolved conflicts. Zero LLM calls allowed.
 * L1: LLM may enrich unresolved descriptive spans only (never intent/tools).
 *
 * Safety intents (unsupported_*) are always L0 and LLM is forbidden.
 */
class TurnTierGate {
    public const L0 = 'L0';
    public const L1 = 'L1';

    /**
     * @param array $partial DeterministicIntentParser output (toArray shape).
     */
    public static function tier(array $partial): string {
        $fields = is_array($partial['resolved_fields'] ?? null) ? $partial['resolved_fields'] : [];
        $intent = (string)($fields['intent']['value'] ?? 'unknown');
        if ($intent === '' || $intent === 'unknown') return self::L0;

        foreach ((array)($partial['conflicts'] ?? []) as $conflict) {
            if (is_array($conflict) && ($conflict['resolved'] ?? true) === false) return self::L0;
            if (is_array($conflict) && !array_key_exists('resolved', $conflict)) return self::L0;
        }

        foreach ((array)($partial['unresolved_spans'] ?? []) as $span) {
            if (is_array($span) && ($span['affects_execution'] ?? false) === true) return self::L1;
        }

        return self::L0;
    }

    /**
     * Whether any LLM call (input parser or entity enricher) is allowed.
     * L0 turns and safety intents must never pay an LLM round trip.
     *
     * @param array $partial DeterministicIntentParser output (toArray shape).
     */
    public static function llmAllowed(array $partial): bool {
        if (self::tier($partial) !== self::L1) return false;
        $fields = is_array($partial['resolved_fields'] ?? null) ? $partial['resolved_fields'] : [];
        $intent = (string)($fields['intent']['value'] ?? 'unknown');
        return !in_array($intent, ['unsupported_outfit', 'unsupported_checkout'], true);
    }
}
