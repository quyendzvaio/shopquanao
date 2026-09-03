<?php

/** Maps the Stylitics Complete the Look boundary only; Stylitics IDs never become shop IDs. */
final class StyliticsStyleReferenceMapper
{
    private const ROLE_NORMALIZE = [
        'shoes' => 'shoe', 'footwear' => 'shoe', 'shoe' => 'shoe',
        'bottoms' => 'bottom', 'bottom' => 'bottom',
        'tops' => 'top', 'top' => 'top',
        'layers' => 'outerwear', 'layer' => 'outerwear', 'outerwear' => 'outerwear',
        'accessories' => 'accessory', 'accessory' => 'accessory',
    ];

    /** @return list<StyleReference> */
    public function map(array $raw, ?string &$occasion = null): array
    {
        $outfits = $raw['outfits'] ?? null;
        if (!is_array($outfits) || $outfits === []) {
            throw new StyliticsApiException('MALFORMED_RESPONSE', 'Stylitics response contains no outfits');
        }

        $occasion = null;
        $references = [];
        foreach ($outfits as $outfitIndex => $outfit) {
            if (!is_array($outfit)) continue;
            $outfitOccasion = is_scalar($outfit['occasion'] ?? null) && trim((string) $outfit['occasion']) !== ''
                ? (string) $outfit['occasion'] : null;
            if ($occasion === null) $occasion = $outfitOccasion;
            $items = is_array($outfit['items'] ?? null) ? $outfit['items'] : [];
            foreach ($items as $itemIndex => $item) {
                if (!is_array($item)) continue;
                try {
                    $references[] = $this->mapItem($item, $outfitOccasion);
                } catch (StyliticsApiException $error) {
                    // Fail-closed per item: an unmappable reference is skipped,
                    // never coerced into a shop card.
                    error_log(json_encode([
                        'provider' => 'stylitics', 'operation' => 'reference_mapping',
                        'success' => false, 'failure_category' => $error->category,
                        'outfit_index' => $outfitIndex, 'item_index' => $itemIndex,
                    ]));
                }
            }
        }

        if ($references === []) {
            throw new StyliticsApiException('EMPTY_STYLITICS_RESPONSE', 'Stylitics response produced no usable references');
        }
        return $this->dedupe($references);
    }

    private function mapItem(array $item, ?string $occasion): StyleReference
    {
        $category = $this->scalarString($item, ['category', 'product_type', 'garment_category']);
        $subcategory = $this->scalarString($item, ['subcategory', 'product_subcategory']);
        $title = $this->scalarString($item, ['title', 'name', 'product_title']);
        $explicitRole = $this->scalarString($item, ['role', 'outfit_role', 'item_role', 'slot']);

        $role = $explicitRole !== null
            ? (self::ROLE_NORMALIZE[strtolower($explicitRole)] ?? null)
            : $this->inferRole($category, $subcategory, $title);
        if ($role === null) {
            throw new StyliticsApiException('UNSUPPORTED_STYLITICS_SCHEMA', 'reference role/category is missing or unmappable');
        }

        $category = $category ?? $subcategory;
        if ($category === null && $title === null) {
            throw new StyliticsApiException('MALFORMED_RESPONSE', 'reference has no category or title');
        }

        $colors = $this->stringList($item['colors'] ?? $item['color'] ?? null);
        $materials = $this->stringList($item['materials'] ?? $item['material'] ?? null);
        $styles = $this->stringList($item['style_tags'] ?? $item['styles'] ?? $item['style'] ?? null);
        $occasionTags = $occasion !== null ? [$occasion] : $this->stringList($item['occasion_tags'] ?? null);
        $referenceId = $this->scalarString($item, ['item_number', 'item_id', 'reference_id', 'sku']);
        $image = $this->usableUrl($this->scalarString($item, ['image_url', 'image', 'imageUrl']));
        $confidence = $this->confidence($item['confidence'] ?? null);

        $parts = array_filter([$title, $category, $subcategory, $colors === [] ? null : implode(', ', $colors), $styles === [] ? null : implode(', ', $styles)]);
        $referenceText = $parts === [] ? null : trim(implode(', ', $parts));

        return new StyleReference(
            $role,
            $category,
            $subcategory,
            $colors,
            $materials,
            $styles,
            $occasionTags,
            null,
            $referenceText,
            $image,
            'stylitics',
            $referenceId,
            $confidence
        );
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

    /** @param array<string,mixed> $item @param list<string> $keys */
    private function scalarString(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $item) || !is_scalar($item[$key])) continue;
            $candidate = trim((string) $item[$key]);
            if ($candidate !== '') return $candidate;
        }
        return null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if ($value === null) return [];
        $values = is_array($value) ? $value : [$value];
        $terms = array_values(array_filter(array_map(
            fn (mixed $term): string => is_scalar($term) ? trim((string) $term) : '',
            $values
        ), fn (string $term): bool => $term !== ''));
        return array_values(array_unique($terms));
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
