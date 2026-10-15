<?php

/**
 * Proactive UC-C line appended to a shopping turn (spec §6.3). The last
 * nudged cart fingerprint lives in chat_session_memory.slots so the same
 * cart is never nudged twice in a session.
 */
final class FreeShippingNudge
{
    public const SHOPPING_INTENTS = [
        'product_search', 'product_detail', 'mixed_product_policy', 'build_outfit', 'shipping', 'order_status',
    ];
    private const SLOT = 'freeship_nudged_fingerprint';

    public function __construct(private PDO $pdo, private int $sessionId)
    {
    }

    public static function eligible(array $result, array $advice, ?string $lastFingerprint, FreeShippingPolicy $policy): bool
    {
        if (!in_array((string)($result['primary_intent'] ?? ''), self::SHOPPING_INTENTS, true)) return false;
        if (in_array((string)($result['response_type'] ?? ''), ['fallback', 'clarification'], true)) return false;
        if (($advice['status'] ?? '') !== FreeShippingGapAdvisor::GAP || empty($advice['products'])) return false;
        $gap = (int)($advice['gap'] ?? 0);
        if ($gap <= 0 || $gap > $policy->nudgeMaxGap) return false;
        return (string)($advice['cart_fingerprint'] ?? '') !== (string)$lastFingerprint;
    }

    public static function message(array $advice): string
    {
        $product = $advice['products'][0];
        return sprintf(
            'Giỏ hàng của bạn còn thiếu %s để được miễn phí ship (đơn từ %s); bạn có thể tham khảo thêm %s (mã %d) giá %s.',
            self::money((int)$advice['gap']),
            self::money((int)$advice['threshold']),
            (string)$product['name'],
            (int)$product['id'],
            self::money((int)round((float)$product['price']))
        );
    }

    public function lastFingerprint(): ?string
    {
        $value = $this->slots()[self::SLOT] ?? null;
        return is_string($value) ? $value : null;
    }

    public function remember(string $fingerprint): void
    {
        $stmt = $this->pdo->prepare('SELECT slots FROM chat_session_memory WHERE session_id = ?');
        $stmt->execute([$this->sessionId]);
        $exists = $stmt->fetchColumn() !== false;
        $slots = $this->slots();
        $slots[self::SLOT] = $fingerprint;
        $json = json_encode($slots, JSON_UNESCAPED_UNICODE);
        if ($exists) {
            $this->pdo->prepare('UPDATE chat_session_memory SET slots = ? WHERE session_id = ?')->execute([$json, $this->sessionId]);
        } else {
            $this->pdo->prepare("INSERT INTO chat_session_memory (session_id, summary, slots) VALUES (?, '', ?)")->execute([$this->sessionId, $json]);
        }
    }

    /** @return array<string,mixed> */
    private function slots(): array
    {
        $stmt = $this->pdo->prepare('SELECT slots FROM chat_session_memory WHERE session_id = ?');
        $stmt->execute([$this->sessionId]);
        $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function money(int $value): string
    {
        return number_format($value, 0, ',', '.') . 'đ';
    }
}
