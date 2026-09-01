<?php

/** UC1 grounding path: external references are inputs only; output is private catalog products. */
final class StyleReferenceCatalogRecommendationService
{
    public function __construct(private StylingReferenceProvider $provider, private PrivateCatalogStyleMapper $mapper) {}

    /** @return array<string,mixed> */
    public function find(int $shopProductId, ?int $shopVariantId = null): array
    {
        $started = microtime(true);
        try {
            $providerStarted = microtime(true);
            $set = $this->provider->referencesForAnchor($shopProductId, $shopVariantId);
            $providerMs = $this->elapsed($providerStarted);
        } catch (Throwable $error) {
            return $this->failure($shopProductId, 'provider_failure', $this->category($error), $started);
        }
        try {
            $searchStarted = microtime(true);
            $mapped = $this->mapper->mapMany($set->references, ['id' => $shopProductId]);
            $searchMs = $this->elapsed($searchStarted);
        } catch (Throwable $error) {
            return $this->failure($shopProductId, 'private_mapping_failure', 'PRIVATE_CATALOG_MAPPING_FAILED', $started);
        }

        $groups = [];
        $products = [];
        foreach ($mapped as $result) {
            $selected = $result->selectedProduct;
            if ($selected !== null) $products[(int) $selected['id']] = $selected;
            $groups[] = [
                'role' => $result->reference->role,
                'category' => $result->reference->category,
                'mapping_status' => $result->mappingStatus,
                'products' => $result->candidates,
                'selected_product_id' => $selected !== null ? (int) $selected['id'] : null,
                'mapping_score' => $result->mappingScore,
            ];
        }
        return [
            'status' => $products === [] ? 'no_products' : 'success',
            'provider_mode' => $set->sourceProvider,
            'anchor_product_id' => $shopProductId,
            'reference_count' => count($set->references),
            'groups' => $groups,
            'products' => array_values($products),
            'provider_error' => null,
            'timings' => [
                'styling_reference_provider_ms' => $providerMs,
                'parallel_product_search_ms' => $searchMs,
                'total_recommendation_ms' => $this->elapsed($started),
            ] + $set->timings,
        ];
    }

    /** @return array<string,mixed> */
    private function failure(int $anchor, string $status, string $error, float $started): array
    {
        return ['status' => $status, 'provider_mode' => 'glance', 'anchor_product_id' => $anchor,
            'reference_count' => 0, 'groups' => [], 'products' => [], 'provider_error' => $error,
            'timings' => ['total_recommendation_ms' => $this->elapsed($started)]];
    }

    private function category(Throwable $error): string
    {
        return property_exists($error, 'category') ? (string) $error->category : 'PROVIDER_UNAVAILABLE';
    }

    private function elapsed(float $started): int { return (int) round((microtime(true) - $started) * 1000); }
}
