<?php

class OnlineValidator {
    public function validate(array $intent, array $normalized, array $response): array {
        $issues = [];
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $answer = trim((string)($response['answer'] ?? $response['message'] ?? ''));

        if ($answer === '') {
            $issues[] = 'empty_answer';
        }
        if (!isset($response['cards']) || !is_array($response['cards'])) {
            $issues[] = 'cards_missing';
        }
        if (!isset($response['response_type'])) {
            $issues[] = 'response_type_missing';
        }
        if (preg_match('/https?:\/\/(?:localhost|127\.0\.0\.1)/i', $answer)) {
            $issues[] = 'raw_local_url';
        }

        if ($primary === 'product_detail') {
            $requestedId = (int)($intent['entities']['product_id'] ?? 0);
            $cards = $response['cards'] ?? [];
            $actualId = isset($cards[0]['id']) ? (int)$cards[0]['id'] : 0;
            if ($requestedId > 0 && $actualId !== $requestedId) {
                $issues[] = 'product_id_mismatch';
            }
        }

        if ($primary === 'order_status') {
            $hasLoginRequirement = false;
            foreach (($normalized['evidence'] ?? []) as $item) {
                if (($item['fact_type'] ?? '') === 'requires_login') {
                    $hasLoginRequirement = true;
                    break;
                }
            }
            if ($hasLoginRequirement && !preg_match('/đăng nhập|dang nhap/ui', $answer)) {
                $issues[] = 'login_instruction_missing';
            }
        }

        return [
            'passed' => $issues === [],
            'issues' => $issues,
            'safe_fallback' => $this->fallbackFor($primary, $intent),
        ];
    }

    private function fallbackFor(string $primary, array $intent): string {
        return match ($primary) {
            'product_detail' => 'Mình chưa lấy được đúng thông tin sản phẩm này. Bạn vui lòng kiểm tra lại mã sản phẩm hoặc thử lại sau.',
            'order_status' => 'Bạn vui lòng đăng nhập để mình kiểm tra trạng thái đơn hàng một cách an toàn.',
            'size_advice' => 'Mình cần đủ chiều cao và cân nặng để tư vấn size chính xác hơn.',
            default => 'Mình chưa đủ dữ liệu đáng tin cậy để trả lời chắc chắn. Bạn vui lòng hỏi rõ hơn giúp mình.',
        };
    }
}
