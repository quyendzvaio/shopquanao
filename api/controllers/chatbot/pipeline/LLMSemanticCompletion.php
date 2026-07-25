<?php

require_once __DIR__ . '/../ProductAttributeNormalizer.php';

class LLMSemanticCompletion {
    private ?LLMProvider $llm;
    private string $lastPrompt = '';

    public function __construct(?LLMProvider $llm) {
        $this->llm = $llm;
    }

    public function complete(array $partial, array $relevantCapabilities): array {
        $spans = array_values(array_filter(
            $partial['unresolved_spans'] ?? [],
            fn($span) => is_array($span) && ($span['affects_execution'] ?? false)
        ));
        $expectedFields = $this->expectedFields($spans);

        if ($spans === [] || $expectedFields === []) {
            return $this->emptyResult(false, 'no_actionable_unresolved_span');
        }
        if ($this->llm === null) {
            return $this->emptyResult(false, 'llm_unavailable');
        }

        $payload = [
            'original_query' => (string)($partial['original_query'] ?? ''),
            'locked_fields' => $this->lockedFields($partial),
            'unresolved_spans' => array_values(array_map(fn($span) => (string)$span['text'], $spans)),
            'expected_fields' => $expectedFields,
            'relevant_capability_definitions' => array_values($relevantCapabilities),
        ];

        $this->lastPrompt = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        try {
            $response = $this->llm->chat([
                [
                    'role' => 'system',
                    'content' => implode("\n", [
                        'Bạn là semantic completion module cho chatbot bán hàng.',
                        'Chỉ đọc unresolved_spans và locked_fields.',
                        'Không parse lại toàn bộ query.',
                        'Không sửa, không lặp lại, không overwrite locked_fields.',
                        'Không chọn tool và không trả prose.',
                        'Nếu trả color, dùng canonical tiếng Việt như "đen", "trắng", "xám"; không dùng "black", "white", "gray".',
                        'Chỉ trả JSON strict theo schema:',
                        '{"inferred_fields":{"occasion":{"value":"interview","confidence":0.9}},"unresolved_remaining":[]}',
                        'Chỉ được dùng field nằm trong expected_fields.',
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => $this->lastPrompt,
                ],
            ], [], 'none');

            $decoded = $this->decodeJson($response->content);
            if ($decoded === null) {
                return $this->emptyResult(true, 'json_parse_failed');
            }

            return [
                'used' => true,
                'inferred_fields' => $this->sanitizeInferredFields(
                    is_array($decoded['inferred_fields'] ?? null) ? $decoded['inferred_fields'] : [],
                    $expectedFields
                ),
                'unresolved_remaining' => is_array($decoded['unresolved_remaining'] ?? null)
                    ? array_values($decoded['unresolved_remaining'])
                    : [],
                'raw_response' => mb_substr($response->content, 0, 2000),
                'prompt' => $this->lastPrompt,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'used' => true,
                'inferred_fields' => [],
                'unresolved_remaining' => array_values(array_map(fn($span) => (string)$span['text'], $spans)),
                'raw_response' => '',
                'prompt' => $this->lastPrompt,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getLastPrompt(): string {
        return $this->lastPrompt;
    }

    private function emptyResult(bool $used, string $reason): array {
        return [
            'used' => $used,
            'inferred_fields' => [],
            'unresolved_remaining' => [],
            'raw_response' => '',
            'prompt' => $this->lastPrompt,
            'error' => $reason,
        ];
    }

    private function lockedFields(array $partial): array {
        $out = [];
        foreach (($partial['resolved_fields'] ?? []) as $field => $metadata) {
            if (is_array($metadata) && ($metadata['locked'] ?? false)) {
                $out[$field] = $metadata['value'] ?? null;
            }
        }
        return $out;
    }

    private function expectedFields(array $spans): array {
        $fields = [];
        foreach ($spans as $span) {
            foreach (($span['expected_fields'] ?? []) as $field) {
                $fields[] = (string)$field;
            }
        }
        return array_values(array_unique(array_filter($fields)));
    }

    private function sanitizeInferredFields(array $fields, array $expectedFields): array {
        $out = [];
        foreach ($fields as $field => $metadata) {
            if (!in_array((string)$field, $expectedFields, true)) {
                continue;
            }
            if (!is_array($metadata)) {
                $metadata = ['value' => $metadata, 'confidence' => 0.7];
            }
            $value = $metadata['value'] ?? null;
            if ((string)$field === 'color') {
                $value = ProductAttributeNormalizer::normalizeColor(is_string($value) ? $value : '') ?? $value;
            }
            $out[(string)$field] = [
                'value' => $value,
                'confidence' => isset($metadata['confidence']) ? (float)$metadata['confidence'] : 0.7,
            ];
        }
        return $out;
    }

    private function decodeJson(string $content): ?array {
        $content = trim($content);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/us', $content, $m)) {
            $decoded = json_decode($m[0], true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }
}
