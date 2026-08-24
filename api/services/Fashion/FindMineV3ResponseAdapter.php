<?php

/** Converts verified FindMine v3/MCP result shapes into provider-independent domain data. */
final class FindMineV3ResponseAdapter
{
    public function toPlan(array $payload, int $anchorProductId): ComplementaryPlan
    {
        if ($anchorProductId <= 0) {
            throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'Anchor product is invalid');
        }

        $raw = $this->unwrapMcpPayload($payload);
        if (($raw['result'] ?? null) === 'error') {
            $reason = trim((string) ($raw['reason'] ?? 'FindMine returned an error result'));
            throw new FindMineProviderException(
                $this->errorCategory($reason),
                $reason,
                false
            );
        }

        $looks = $raw['looks'] ?? null;
        if (!is_array($looks)) {
            throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine response is missing looks');
        }

        $requirements = [];
        $providerItems = [];
        foreach ($looks as $lookIndex => $look) {
            if (!is_array($look)) {
                throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine look is malformed');
            }
            $items = $look['items'] ?? $look['products'] ?? [];
            if (!is_array($items)) {
                throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine look items are malformed');
            }
            foreach ($items as $itemIndex => $item) {
                if (!is_array($item)) {
                    throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine item is malformed');
                }
                $providerId = trim((string) ($item['item_id'] ?? $item['product_id'] ?? ''));
                if ($providerId === '') {
                    throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine item is missing item_id');
                }
                $providerItem = [
                    'provider_item_id' => $providerId,
                    'look_id' => trim((string) ($look['look_id'] ?? $look['id'] ?? '')),
                    'title' => trim((string) ($item['title'] ?? $item['name'] ?? '')),
                    'item_url' => trim((string) ($item['item_url'] ?? $item['url'] ?? '')),
                    'image_url' => trim((string) ($item['image_url'] ?? '')),
                    'price' => $item['price'] ?? null,
                    'look_index' => (int) $lookIndex,
                    'item_index' => (int) $itemIndex,
                ];
                $providerItems[] = $providerItem;

                $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
                $category = trim((string) ($item['subcategory'] ?? $item['category'] ?? $item['product_type'] ?? $attributes['subcategory'] ?? $attributes['category'] ?? $attributes['product_type'] ?? ''));
                if ($category === '') {
                    throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine item is missing a structured category');
                }
                $colors = $this->terms($item['colors'] ?? $item['color'] ?? $attributes['colors'] ?? $attributes['color'] ?? []);
                $styles = $this->terms($item['styles'] ?? $item['style'] ?? $attributes['styles'] ?? $attributes['style'] ?? []);
                $materials = $this->terms($item['materials'] ?? $item['material'] ?? $attributes['materials'] ?? $attributes['material'] ?? []);
                $subcategoryValue = $item['subcategory'] ?? $attributes['subcategory'] ?? null;
                $subcategory = $subcategoryValue !== null ? trim((string) $subcategoryValue) : null;
                $requirements[] = new ComplementaryItemRequirement(
                    $category,
                    $styles,
                    $colors,
                    $materials,
                    max(1, min(100, (int) ($item['priority'] ?? ($lookIndex + 1)))),
                    $subcategory !== '' ? $subcategory : null
                );
            }
        }

        if ($requirements === []) {
            throw new FindMineProviderException('EMPTY_RECOMMENDATION', 'FindMine returned no complementary items');
        }

        return new ComplementaryPlan(
            $anchorProductId,
            $this->dedupeRequirements($requirements),
            $providerItems,
            isset($raw['response_uuid']) ? trim((string) $raw['response_uuid']) : null
        );
    }

    private function unwrapMcpPayload(array $payload): array
    {
        if (isset($payload['content']) && is_array($payload['content'])) {
            foreach ($payload['content'] as $content) {
                if (is_array($content) && ($content['type'] ?? '') === 'text') {
                    $decoded = json_decode((string) ($content['text'] ?? ''), true);
                    if (is_array($decoded)) {
                        return $this->normalizeIntendedMcpShape($decoded);
                    }
                }
            }
            throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine MCP content is not valid JSON');
        }
        return $payload;
    }

    private function normalizeIntendedMcpShape(array $payload): array
    {
        if (isset($payload['looks']) && is_array($payload['looks'])) {
            $looks = [];
            foreach ($payload['looks'] as $look) {
                if (!is_array($look)) continue;
                $products = $look['products'] ?? $look['items'] ?? [];
                $items = [];
                foreach (is_array($products) ? $products : [] as $product) {
                    if (!is_array($product)) continue;
                    $items[] = [
                        'item_id' => $product['item_id'] ?? $product['product_id'] ?? null,
                        'title' => $product['title'] ?? $product['name'] ?? null,
                        'item_url' => $product['item_url'] ?? null,
                        'image_url' => $product['image_url'] ?? null,
                        'price' => $product['price'] ?? null,
                        'category' => $product['category'] ?? null,
                        'subcategory' => $product['subcategory'] ?? null,
                        'color' => $product['color'] ?? null,
                        'style' => $product['style'] ?? null,
                        'material' => $product['material'] ?? null,
                        'attributes' => is_array($product['attributes'] ?? null) ? $product['attributes'] : [],
                    ];
                }
                $looks[] = ['look_id' => $look['look_id'] ?? null, 'items' => $items];
            }
            return [
                'result' => $payload['result'] ?? 'success',
                'response_uuid' => $payload['response_uuid'] ?? null,
                'looks' => $looks,
            ];
        }
        return $payload;
    }

    /** @return list<string> */
    private function terms(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return array_values(array_filter(array_map(
            fn ($term) => is_scalar($term) ? trim((string) $term) : '',
            $values
        ), fn ($term) => $term !== ''));
    }

    /** @param list<ComplementaryItemRequirement> $requirements */
    private function dedupeRequirements(array $requirements): array
    {
        $result = [];
        $seen = [];
        foreach ($requirements as $requirement) {
            $key = json_encode([
                $requirement->category,
                $requirement->subcategory,
                $requirement->styles,
                $requirement->colors,
                $requirement->materials,
            ], JSON_UNESCAPED_UNICODE);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $requirement;
        }
        return $result;
    }

    private function errorCategory(string $reason): string
    {
        return preg_match('/invalid[_ ]?store|application|auth/i', $reason)
            ? 'AUTHENTICATION_ERROR'
            : 'EMPTY_RECOMMENDATION';
    }
}
