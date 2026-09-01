<?php

/** Maps only the provider response boundary; Glance IDs never become shop IDs. */
final class GlanceLiveResponseMapper
{
    /** @return list<StyleReference> */
    public function map(array $mcpResult): array
    {
        $structured = $mcpResult['structuredContent'] ?? null;
        $items = $this->extractItems($structured);
        $source = 'structuredContent';

        if ($items === []) {
            $decoded = $this->decodeTextContent($mcpResult['content'] ?? null);
            $items = $this->extractItems($decoded);
            $source = 'text-fallback';
        }

        if ($items === []) {
            throw new GlanceResponseMappingException(
                'EMPTY_GLANCE_RESPONSE',
                'Glance response contains no mappable styling references'
            );
        }

        $references = [];
        foreach ($items as $index => $item) {
            try {
                $references[] = $this->mapItem($item, $index);
            } catch (GlanceResponseMappingException $exception) {
                throw new GlanceResponseMappingException(
                    'PARTIAL_GLANCE_REFERENCE',
                    sprintf('Glance reference %d is invalid: %s', $index, $exception->getMessage())
                );
            }
        }

        if ($references === []) {
            throw new GlanceResponseMappingException(
                'EMPTY_GLANCE_RESPONSE',
                sprintf('Glance %s response produced no usable references', $source)
            );
        }

        return $this->dedupe($references);
    }

    /** @return list<array<string,mixed>> */
    private function extractItems(mixed $value, int $depth = 0, array $context = []): array
    {
        if ($depth > 8 || !is_array($value)) return [];

        // Glance's live shape wraps products in outfits; never treat an outfit
        // object (which also has a description) as a product reference.
        foreach (['outfits', 'products', 'items', 'references', 'recommendations', 'looks'] as $containerKey) {
            if (array_key_exists($containerKey, $value)) {
                if ($containerKey === 'outfits' && is_array($value[$containerKey])) {
                    $items = [];
                    foreach ($value[$containerKey] as $outfit) {
                        if (!is_array($outfit)) continue;
                        $outfitContext = $context;
                        if (is_scalar($outfit['occasion'] ?? null)) {
                            $outfitContext['occasion'] = (string) $outfit['occasion'];
                        }
                        foreach ($this->extractItems($outfit, $depth + 1, $outfitContext) as $item) $items[] = $item;
                    }
                } else {
                    $items = $this->extractItems($value[$containerKey], $depth + 1, $context);
                }
                if ($items !== []) return $items;
            }
        }

        if ($this->looksLikeItem($value)) {
            return [$context === [] ? $value : array_merge($context, $value)];
        }

        $items = [];
        foreach ($value as $child) {
            if (!is_array($child)) continue;
            foreach ($this->extractItems($child, $depth + 1, $context) as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private function looksLikeItem(array $value): bool
    {
        foreach ([
            'role', 'outfit_role', 'item_role', 'slot', 'category', 'subcategory', 'product_type',
            'title', 'name', 'description', 'image_url', 'image', 'color', 'colors',
            'style', 'styles', 'style_tags', 'item_id', 'product_id', 'reference_id', 'sku', 'merchantVariantId',
        ] as $key) {
            if (array_key_exists($key, $value) && !is_array($value[$key])) return true;
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function decodeTextContent(mixed $content): ?array
    {
        if (!is_array($content)) return null;
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'text' || !is_string($block['text'] ?? null)) continue;
            $text = trim($block['text']);
            $decoded = json_decode($text, true);
            if (is_array($decoded)) return $decoded;

            $startObject = strpos($text, '{');
            $endObject = strrpos($text, '}');
            $startArray = strpos($text, '[');
            $endArray = strrpos($text, ']');
            $start = $startObject === false ? $startArray : ($startArray === false ? $startObject : min($startObject, $startArray));
            $end = max($endObject === false ? -1 : $endObject, $endArray === false ? -1 : $endArray);
            if ($start !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($decoded)) return $decoded;
            }
        }
        return null;
    }

    private function mapItem(array $item, int $index): StyleReference
    {
        $title = $this->stringValue($item, ['title', 'name', 'product_title']);
        $description = $this->stringValue($item, ['description', 'summary']);
        $category = $this->stringValue($item, ['category', 'product_type', 'garment_category']);
        $subcategory = $this->stringValue($item, ['subcategory', 'product_subcategory']);
        $explicitRole = $this->stringValue($item, ['role', 'outfit_role', 'item_role', 'slot']);
        $role = $explicitRole !== null
            ? $this->normalizeRole($explicitRole)
            : $this->inferRole($category, $subcategory, $title);

        if ($role === null) {
            throw new GlanceResponseMappingException('UNSUPPORTED_GLANCE_SCHEMA', 'reference role/category is missing');
        }
        if ($category === null && $subcategory !== null) $category = $subcategory;
        if ($category === null && $title === null) {
            throw new GlanceResponseMappingException('MALFORMED_GLANCE_RESPONSE', 'reference has no category or title');
        }

        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
        $colors = $this->terms($item, $attributes, ['colors', 'color', 'color_name', 'primary_color']);
        $materials = $this->terms($item, $attributes, ['materials', 'material']);
        $styles = $this->terms($item, $attributes, ['style_tags', 'styles', 'style']);
        $occasions = $this->terms($item, $attributes, ['occasion_tags', 'occasions', 'occasion']);
        $silhouette = $this->stringValue($item, ['silhouette']) ?? $this->stringValue($attributes, ['silhouette']);
        $referenceId = $this->stringValue($item, ['item_id', 'product_id', 'reference_id', 'referenceId', 'sku', 'merchantVariantId']);
        $image = $this->usableUrl($this->stringValue($item, ['image_url', 'image', 'imageUrl']));
        $confidence = $this->confidence($item['confidence'] ?? null);

        $referenceText = $this->referenceText($title, $description, $category, $subcategory, $styles, $colors);
        return new StyleReference(
            $role,
            $category,
            $subcategory,
            $colors,
            $materials,
            $styles,
            $occasions,
            $silhouette,
            $referenceText,
            $image,
            'glance',
            $referenceId,
            $confidence
        );
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $attributes @param list<string> $keys @return list<string> */
    private function terms(array $item, array $attributes, array $keys): array
    {
        foreach ($keys as $key) {
            $value = array_key_exists($key, $item) ? $item[$key] : ($attributes[$key] ?? null);
            if ($value === null) continue;
            $values = is_array($value) ? $value : [$value];
            $terms = array_values(array_filter(array_map(
                fn (mixed $term): string => is_scalar($term) ? trim((string) $term) : '',
                $values
            ), fn (string $term): bool => $term !== ''));
            if ($terms !== []) return array_values(array_unique($terms));
        }
        return [];
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private function stringValue(array $value, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value) || !is_scalar($value[$key])) continue;
            $candidate = trim((string) $value[$key]);
            if ($candidate !== '') return $candidate;
        }
        return null;
    }

    private function inferRole(?string $category, ?string $subcategory, ?string $title): ?string
    {
        $text = strtolower(trim(implode(' ', array_filter([$category, $subcategory, $title]))));
        if ($text === '') return null;
        if (preg_match('/shoe|footwear|sneaker|loafer|boot|sandal|heel/', $text)) return 'shoe';
        if (preg_match('/bottom|trouser|pant|jean|chino|short|skirt|denim/', $text)) return 'bottom';
        if (preg_match('/outerwear|jacket|blazer|coat|cardigan|vest/', $text)) return 'outerwear';
        if (preg_match('/accessor|bag|belt|hat|scarf|watch|jewel|tie/', $text)) return 'accessory';
        if (preg_match('/top|shirt|tee|t-shirt|blouse|sweater|hoodie|kurta/', $text)) return 'top';
        return null;
    }

    private function normalizeRole(string $role): string
    {
        return match (strtolower(trim($role))) {
            'shoes', 'footwear', 'shoe' => 'shoe',
            'bottoms', 'bottom' => 'bottom',
            'tops', 'top' => 'top',
            'layers', 'layer', 'outerwear' => 'outerwear',
            'accessories', 'accessory' => 'accessory',
            default => trim($role),
        };
    }

    private function usableUrl(?string $value): ?string
    {
        if ($value === null || !filter_var($value, FILTER_VALIDATE_URL)) return null;
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    private function confidence(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) return null;
        return max(0.0, min(1.0, (float) $value));
    }

    /** @param list<string> $styles @param list<string> $colors */
    private function referenceText(?string $title, ?string $description, ?string $category, ?string $subcategory, array $styles, array $colors): ?string
    {
        $parts = array_filter([$title, $category, $subcategory, $colors === [] ? null : implode(', ', $colors), $styles === [] ? null : implode(', ', $styles)]);
        if ($parts === [] && $description !== null) $parts[] = $description;
        return $parts === [] ? null : trim(implode(', ', $parts));
    }

    /** @param list<StyleReference> $references @return list<StyleReference> */
    private function dedupe(array $references): array
    {
        $result = [];
        $seen = [];
        foreach ($references as $reference) {
            $key = $reference->sourceReferenceId !== null
                ? 'id:' . $reference->sourceReferenceId
                : 'value:' . json_encode($reference->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $reference;
        }
        return $result;
    }
}
