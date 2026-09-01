<?php

/** Shared raw-suggestion pipeline used by explicit and proactive styling. */
final class ComplementaryProductFinder
{
    public function __construct(
        private RawFashionSuggestionProvider $provider,
        private FashionAttributeExtractor $extractor,
        private FashionRequirementNormalizer $normalizer,
        private ParallelComplementaryProductSearcher $searcher
    ) {}

    /** @return array<string,mixed> */
    public function find(int $shopProductId, ?int $shopVariantId = null): array
    {
        if ($shopProductId <= 0) throw new InvalidArgumentException('shopProductId must be positive');
        $started = microtime(true);
        $timings = [];

        try {
            $stage = microtime(true);
            $suggestions = $this->provider->suggestForAnchor($shopProductId, $shopVariantId);
            $timings['styling_reference_provider_ms'] = $this->elapsed($stage);
        } catch (Throwable $error) {
            return $this->failure('provider_failure', $shopProductId, $timings + [
                'total_recommendation_ms' => $this->elapsed($started),
            ], property_exists($error, 'category') ? (string) $error->category : 'PROVIDER_UNAVAILABLE');
        }

        if ($suggestions === []) {
            return $this->failure('no_suggestions', $shopProductId, $timings + [
                'total_recommendation_ms' => $this->elapsed($started),
            ], 'EMPTY_RECOMMENDATION');
        }

        try {
            $stage = microtime(true);
            $extracted = $this->extractor->extract($suggestions);
            $timings['llm_extraction_ms'] = $this->elapsed($stage);
        } catch (Throwable $error) {
            $timings['llm_extraction_ms'] = $this->elapsed($stage);
            return $this->failure('extraction_failure', $shopProductId, $timings + [
                'total_recommendation_ms' => $this->elapsed($started),
            ], $error instanceof FashionExtractionException ? $error->category : 'llm_failure');
        }

        $stage = microtime(true);
        $requirements = $this->normalizer->normalize($extracted);
        $timings['normalization_ms'] = $this->elapsed($stage);
        if ($requirements === []) {
            return $this->failure('no_requirements', $shopProductId, $timings + [
                'total_recommendation_ms' => $this->elapsed($started),
            ], 'invalid_or_unknown_extraction');
        }

        $stage = microtime(true);
        $groups = $this->searcher->search($requirements);
        $timings['parallel_product_search_ms'] = $this->elapsed($stage);
        $products = [];
        foreach ($groups as $group) {
            foreach (($group['products'] ?? []) as $product) {
                if (!is_array($product) || (int) ($product['id'] ?? 0) <= 0) continue;
                $products[(int) $product['id']] = $product;
            }
        }
        $timings['total_recommendation_ms'] = $this->elapsed($started);

        return [
            'status' => $products === [] ? 'no_products' : 'success',
            'provider_mode' => $suggestions[0]->source ?? 'glance',
            'anchor_product_id' => $shopProductId,
            'raw_suggestions' => array_map(static fn (RawFashionSuggestion $suggestion): array => [
                'text' => $suggestion->text,
                'source' => $suggestion->source,
            ], $suggestions),
            'extracted_items' => array_map(static fn (ExtractedFashionItem $item): array => $item->toArray(), $extracted),
            'normalized_requirements' => array_map(fn (FashionRequirement $requirement): array => $this->requirementArray($requirement), $requirements),
            'groups' => $groups,
            'products' => array_values($products),
            'provider_error' => null,
            'timings' => $timings,
        ];
    }

    /** @return array<string,mixed> */
    private function failure(string $status, int $anchor, array $timings, string $error): array
    {
        return [
            'status' => $status,
            'provider_mode' => 'glance',
            'anchor_product_id' => $anchor,
            'raw_suggestions' => [],
            'extracted_items' => [],
            'normalized_requirements' => [],
            'groups' => [],
            'products' => [],
            'provider_error' => $error,
            'timings' => $timings,
        ];
    }

    /** @return array<string,mixed> */
    private function requirementArray(FashionRequirement $requirement): array
    {
        return [
            'priority' => $requirement->sourcePriority,
            'raw_category' => $requirement->sourceCategory,
            'search' => $requirement->search,
            'category_id' => $requirement->categoryId,
            'category' => $requirement->canonicalCategory,
            'subcategory' => $requirement->subcategory,
            'colors' => $requirement->colors,
            'materials' => $requirement->materials,
            'styles' => $requirement->styles,
            'patterns' => $requirement->patterns,
            'fits' => $requirement->fits,
            'text_fallback' => $requirement->textFallback,
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
