<?php

/** Converts synthetic FindMine MCP items to raw styling text, never shop identity. */
final class FindMineDemoFashionProvider implements RawFashionSuggestionProvider
{
    public function __construct(private FindMineMcpClientContract $client) {}

    public function suggestForAnchor(int $shopProductId, ?int $shopVariantId = null): array
    {
        if ($shopProductId <= 0) {
            throw new InvalidArgumentException('shopProductId must be positive');
        }

        $payload = $this->client->call('get_complete_the_look', [
            'product_id' => 'shopquanao-demo-anchor-' . $shopProductId,
            'in_stock' => true,
            'on_sale' => false,
            'return_pdp_item' => false,
            'fake_result' => true,
        ]);
        $raw = $this->decodeMcpPayload($payload);
        if (($raw['result'] ?? 'success') === 'error') {
            throw new FindMineProviderException(
                'PROVIDER_UNAVAILABLE',
                trim((string) ($raw['reason'] ?? 'FindMine demo returned an error')),
                true
            );
        }

        $suggestions = [];
        foreach (($raw['looks'] ?? []) as $look) {
            if (!is_array($look)) continue;
            $items = $look['products'] ?? $look['items'] ?? [];
            if (!is_array($items)) continue;
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $text = trim((string) ($item['title'] ?? $item['name'] ?? ''));
                if ($text === '') continue;
                $contextParts = [$text];
                if (isset($item['category']) && is_string($item['category']) && trim($item['category']) !== '') {
                    $contextParts[] = 'category: ' . trim($item['category']);
                }
                if (is_array($item['attributes'] ?? null)) {
                    foreach ($item['attributes'] as $key => $value) {
                        if (is_scalar($value) && trim((string) $value) !== '') {
                            $contextParts[] = (string) $key . ': ' . trim((string) $value);
                        }
                    }
                }
                $text = implode('; ', $contextParts);
                $suggestions[] = new RawFashionSuggestion($text, 'findmine_demo', array_filter([
                    'provider_item_id' => $item['item_id'] ?? $item['product_id'] ?? null,
                    'look_id' => $look['look_id'] ?? $look['id'] ?? null,
                    'response_uuid' => $raw['response_uuid'] ?? null,
                    'category' => $item['category'] ?? null,
                    'attributes' => is_array($item['attributes'] ?? null) ? $item['attributes'] : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
            }
        }

        if ($suggestions === []) {
            throw new FindMineProviderException('EMPTY_RECOMMENDATION', 'FindMine demo returned no usable styling text');
        }
        return $suggestions;
    }

    /** @return array<string,mixed> */
    private function decodeMcpPayload(array $payload): array
    {
        if (is_array($payload['looks'] ?? null)) return $payload;
        foreach (($payload['content'] ?? []) as $content) {
            if (!is_array($content) || ($content['type'] ?? '') !== 'text') continue;
            $decoded = json_decode((string) ($content['text'] ?? ''), true);
            if (is_array($decoded)) return $decoded;
        }
        throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine demo MCP content is not valid JSON');
    }
}
