<?php

/**
 * The only memory that leaves PHP for chat-graph (spec 5.5): an allow-list of
 * session slots, the summary, and, for a logged-in user, a short profile.
 * Anything not listed here (health, skin, events, feedback, brand, product_type,
 * color, style, ...) never reaches the LLM provider.
 */
final class ChatMemoryContext
{
    public const ALLOWED_SLOTS = ['size', 'height_cm', 'weight_kg', 'budget', 'min_price', 'max_price', 'gender'];
    private const PROFILE_FACTS = ['usual_size'];
    private const PROFILE_PREFERENCES = ['favorite_color', 'favorite_style', 'favorite_texture', 'avoid_material', 'avoid_style'];
    private const RECENT_PURCHASES = 5;

    /**
     * @param array<string,mixed> $memory the return of ChatbotMemory::rememberUserMessage
     * @return array{summary:string, slots:array<string,string|int|float>, profile:?array<string,mixed>}
     */
    public static function build(array $memory, ?int $userId): array
    {
        $slots = [];
        $stored = is_array($memory['slots'] ?? null) ? $memory['slots'] : [];
        foreach (self::ALLOWED_SLOTS as $key) {
            $value = $stored[$key] ?? null;
            if (is_string($value) || is_int($value) || is_float($value)) {
                $slots[$key] = $value;
            }
        }

        return [
            'summary' => (string) ($memory['summary'] ?? ''),
            'slots' => $slots,
            'profile' => $userId === null ? null : self::profile(is_array($memory['long_term'] ?? null) ? $memory['long_term'] : []),
        ];
    }

    /** @param array<string,mixed> $longTerm @return array<string,mixed> */
    private static function profile(array $longTerm): array
    {
        $facts = is_array($longTerm['stable_facts'] ?? null) ? $longTerm['stable_facts'] : [];
        $preferences = is_array($longTerm['preferences'] ?? null) ? $longTerm['preferences'] : [];
        $profile = [];
        foreach (self::PROFILE_FACTS as $key) {
            $profile[$key] = $facts[$key] ?? null;
        }
        foreach (self::PROFILE_PREFERENCES as $key) {
            $profile[$key] = $preferences[$key] ?? null;
        }
        // purchase_history is newest first (OrderRepository::recentPurchases).
        $names = [];
        foreach (array_slice(is_array($longTerm['purchase_history'] ?? null) ? $longTerm['purchase_history'] : [], 0, self::RECENT_PURCHASES) as $purchase) {
            $name = is_array($purchase) ? trim((string) ($purchase['product'] ?? '')) : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }
        $profile['recent_purchases'] = $names;

        return array_filter($profile, static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
