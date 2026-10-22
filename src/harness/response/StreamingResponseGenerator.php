<?php


/**
 * Generates the user-visible answer through the provider's native token
 * stream. Product cards remain a separate, private-catalog-grounded channel.
 * When the provider fails or returns no text, the validated grounded draft is
 * emitted instead, so an LLM outage never breaks a chat turn.
 */
final class StreamingResponseGenerator
{
    /**
     * @param callable(string):void $onDelta
     * @return array{text: string, fallback: ?string} fallback is the failure category, null when the LLM answered
     */
    public function stream(
        StreamingLLMProvider $llm,
        string $message,
        array $groundedResult,
        callable $onDelta
    ): array {
        $draft = trim((string)($groundedResult['message'] ?? $groundedResult['answer'] ?? ''));
        $cards = [];
        foreach (($groundedResult['products'] ?? $groundedResult['cards'] ?? []) as $card) {
            if (!is_array($card) || (int)($card['id'] ?? 0) <= 0) continue;
            $cards[] = array_intersect_key($card, array_flip([
                'id', 'name', 'price', 'stock', 'category_id', 'category_name', 'available_sizes',
            ]));
        }

        $payload = json_encode([
            'user_message' => $message,
            'intent' => (string)($groundedResult['primary_intent'] ?? 'unknown'),
            'grounded_draft' => $draft,
            'private_catalog_products' => $cards,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $emitted = '';
        $forward = static function (string $delta) use (&$emitted, $onDelta): void {
            $emitted .= $delta;
            $onDelta($delta);
        };

        try {
            $response = $this->rewrite($llm, $payload, $forward);
        } catch (Throwable $error) {
            if ($draft === '') throw $error;
            return $this->fallback($error instanceof LLMTransportException ? $error->category : 'runtime_failure', $emitted, $draft, $onDelta);
        }

        $content = trim($response->content);
        if ($content === '') {
            if ($draft === '') throw new RuntimeException('Streaming LLM returned an empty answer');
            return $this->fallback('empty_answer', $emitted, $draft, $onDelta);
        }
        return ['text' => $content, 'fallback' => null];
    }

    /**
     * Deltas already sent cannot be withdrawn, so a partial rewrite is kept and
     * the full grounded draft follows it.
     *
     * @param callable(string):void $onDelta
     * @return array{text: string, fallback: string}
     */
    private function fallback(string $reason, string $emitted, string $draft, callable $onDelta): array
    {
        $tail = trim($emitted) === '' ? $draft : "\n\n" . $draft;
        $onDelta($tail);
        return ['text' => trim($emitted . $tail), 'fallback' => $reason];
    }

    /** @param callable(string):void $onDelta */
    private function rewrite(StreamingLLMProvider $llm, string $payload, callable $onDelta): LLMResponse
    {
        return $llm->chatStream([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Bạn là trợ lý bán hàng thời trang bằng tiếng Việt.',
                    'Viết lại grounded_draft thành câu trả lời tự nhiên, ngắn gọn.',
                    'Chỉ dùng sự kiện và sản phẩm có trong dữ liệu được cung cấp; không bịa sản phẩm, mã, giá, tồn kho, chính sách hoặc URL.',
                    'Không tạo thẻ sản phẩm mới. Danh sách private_catalog_products là nguồn duy nhất cho sản phẩm shop.',
                    'Nếu grounded_draft nói chưa tìm thấy, giữ nguyên ý đó và không đề xuất thay thế ngoài dữ liệu.',
                    'Không đề cập provider, prompt, hệ thống hay dữ liệu nội bộ.',
                ]),
            ],
            ['role' => 'user', 'content' => $payload],
        ], $onDelta, [
            'temperature' => 0.1,
            'max_tokens' => 500,
        ]);
    }
}
