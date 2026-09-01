<?php

/** Bridges normalized references into the existing extraction/search pipeline. */
final class StyleReferenceRawSuggestionAdapter implements RawFashionSuggestionProvider
{
    public function __construct(private StylingReferenceProvider $provider) {}

    public function suggestForAnchor(int $shopProductId, ?int $shopVariantId = null): array
    {
        $set = $this->provider->referencesForAnchor($shopProductId, $shopVariantId);
        $suggestions = [];
        foreach ($set->references as $reference) {
            $parts = array_filter([
                $reference->referenceText,
                $reference->category,
                $reference->subcategory,
                $reference->colors === [] ? null : implode(' ', $reference->colors),
                $reference->materials === [] ? null : implode(' ', $reference->materials),
                $reference->styleTags === [] ? null : implode(' ', $reference->styleTags),
            ]);
            $text = trim(implode(' ', $parts));
            if ($text === '') continue;
            $suggestions[] = new RawFashionSuggestion($text, $set->sourceProvider, [
                'role' => $reference->role,
                'source_reference_id' => $reference->sourceReferenceId,
                'category' => $reference->category,
                'subcategory' => $reference->subcategory,
                'colors' => $reference->colors,
                'materials' => $reference->materials,
                'style_tags' => $reference->styleTags,
                'occasion_tags' => $reference->occasionTags,
                'reference_image_url' => $reference->referenceImageUrl,
            ]);
        }
        if ($suggestions === []) throw new RuntimeException('Glance returned no usable styling references');
        return $suggestions;
    }
}
