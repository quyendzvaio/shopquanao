<?php

/**
 * Proactive UC-C line appended to a shopping turn (spec §6.3). The last
 * nudged cart fingerprint lives in chat_session_memory.slots so the same
 * cart is never nudged twice in a session. Optional afterTurn middleware:
 * a failure never breaks the chat turn.
 */
final class FreeShippingNudge extends BaseMiddleware
{
    public const SHOPPING_INTENTS = [
        'product_search', 'product_detail', 'mixed_product_policy', 'build_outfit', 'shipping', 'order_status',
    ];
    private const SLOT = 'freeship_nudged_fingerprint';

    public function __construct(
        private ChatMemoryRepository $memory,
        private int $sessionId,
        private FreeShippingPolicy $policy,
        private FreeShippingService $service,
    ) {
    }

    /**
     * Appends the nudge sentence and its product card, at most once per cart
     * state; always sets freeship_nudge. On a streamed turn the sentence is
     * its own delta after the whole answer, so an LLM rewrite cannot drop it.
     */
    public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult
    {
        $nudge = $this->nudge($turn->userId, $result->toArray());
        $result = $result->with('freeship_nudge', $nudge !== null);
        if ($nudge === null) {
            return $result;
        }

        $byId = [];
        foreach (($result->get('products') ?? []) as $card) {
            if (is_array($card)) $byId[(int)($card['id'] ?? 0)] = $card;
        }
        $byId[(int)$nudge['card']['id']] ??= $nudge['card'];
        $products = array_values($byId);

        // Streamed deltas cannot be withdrawn, so a streamed answer is kept as sent.
        $answer = (string)$result->get('message');
        if ($turn->emit === null) {
            $answer = rtrim($answer);
        }
        $turn->emit(' ' . $nudge['text']);
        $message = $answer . ' ' . $nudge['text'];

        return $result
            ->with('products', $products)
            ->with('cards', $products)
            ->with('message', $message)
            ->with('answer', $message);
    }

    public static function turnQualifies(array $result): bool
    {
        if (!in_array((string)($result['primary_intent'] ?? ''), self::SHOPPING_INTENTS, true)) return false;
        return !in_array((string)($result['response_type'] ?? ''), ['fallback', 'clarification'], true);
    }

    public static function eligible(array $result, array $advice, ?string $lastFingerprint, FreeShippingPolicy $policy): bool
    {
        if (!self::turnQualifies($result)) return false;
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
        $exists = $this->memory->slotsJson($this->sessionId) !== false;
        $slots = $this->slots();
        $slots[self::SLOT] = $fingerprint;
        $json = json_encode($slots, JSON_UNESCAPED_UNICODE);
        if ($exists) {
            $this->memory->updateSlots($this->sessionId, $json);
        } else {
            $this->memory->insertSlots($this->sessionId, $json);
        }
    }

    /** @return array<string,mixed> */
    private function slots(): array
    {
        $decoded = json_decode((string)($this->memory->slotsJson($this->sessionId) ?: ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{text:string,card:array}|null */
    private function nudge(?int $userId, array $result): ?array
    {
        if ($userId === null || !self::turnQualifies($result)) return null;
        try {
            $advice = $this->service->suggest($userId, ['limit' => 1]);
            if (!self::eligible($result, $advice, $this->lastFingerprint(), $this->policy)) return null;
            $cards = (new EvidenceNormalizer())->productCards($advice['products']);
            if ($cards === []) return null;
            $this->remember((string)$advice['cart_fingerprint']);
            return ['text' => self::message($advice), 'card' => $cards[0]];
        } catch (Throwable) {
            error_log(json_encode(['operation' => 'freeship_nudge', 'success' => false, 'error_category' => 'suppressed_runtime_failure']));
            return null;
        }
    }

    private static function money(int $value): string
    {
        return number_format($value, 0, ',', '.') . 'đ';
    }
}
