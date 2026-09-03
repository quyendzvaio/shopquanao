<?php

require_once dirname(__DIR__, 2) . '/cache/Cache.php';

/** Live Stylitics edge: private anchor -> Complete the Look HTTP API -> StyleReferenceSet. */
final class StyliticsStylingProvider implements StylingReferenceProvider
{
    public function __construct(
        private StyliticsConfig $config,
        private ?StyliticsAnchorSkuResolverContract $skuResolver = null,
        private ?StyliticsHttpClientContract $client = null,
        private ?StyliticsStyleReferenceMapper $mapper = null
    ) {}

    public function referencesForAnchor(int $shopProductId, ?int $shopVariantId = null): StyleReferenceSet
    {
        if (!$this->config->enabled || $this->config->mode !== 'live') throw new StyliticsApiException('PROVIDER_DISABLED', 'Stylitics live provider is not enabled');
        if ($this->skuResolver === null) throw new StyliticsApiException('PROVIDER_MISCONFIGURED', 'Stylitics SKU resolver is required');
        $started = microtime(true);
        $ttl = $this->cacheTtl();
        try {
            $skuStarted = microtime(true);
            $anchorSku = $this->skuResolver->resolveSku($shopProductId, $shopVariantId);
            $skuMs = $this->elapsed($skuStarted);

            $cacheKey = 'stylitics-style-reference:v1:' . hash('sha256', json_encode([
                'product_id' => $shopProductId,
                'variant_id' => $shopVariantId,
                'api_url' => $this->config->apiUrl,
                'anchor_sku' => $anchorSku,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $cached = $ttl > 0 ? $this->fromCache(Cache::get($cacheKey), $shopProductId) : null;
            if ($cached !== null) {
                $timings = $cached->timings + [
                    'style_reference_cache_hit' => true,
                    'style_reference_cache_ttl_seconds' => $ttl,
                    'stylitics_anchor_sku_ms' => $skuMs,
                    'style_reference_total_ms' => $this->elapsed($started),
                ];
                $set = new StyleReferenceSet($cached->anchorProductId, $cached->occasion, $cached->references, $cached->sourceProvider, $timings);
                $this->observe(true, null, $set);
                return $set;
            }

            $apiStarted = microtime(true);
            $raw = ($this->client ?? new StyliticsHttpClient($this->config))->completeTheLook($anchorSku, $shopVariantId !== null ? $anchorSku : null);
            $apiMs = $this->elapsed($apiStarted);

            $mapStarted = microtime(true);
            $occasion = null;
            $references = ($this->mapper ?? new StyliticsStyleReferenceMapper())->map($raw, $occasion);
            $mapMs = $this->elapsed($mapStarted);

            $timings = [
                'style_reference_cache_hit' => false,
                'style_reference_cache_ttl_seconds' => $ttl,
                'stylitics_anchor_sku_ms' => $skuMs,
                'stylitics_api_ms' => $apiMs,
                'stylitics_response_mapping_ms' => $mapMs,
                'style_reference_total_ms' => $this->elapsed($started),
            ];
            $set = new StyleReferenceSet($shopProductId, $occasion, $references, 'stylitics', $timings);
            if ($ttl > 0 && $references !== []) Cache::set($cacheKey, $this->cacheValue($set), $ttl);
            $this->observe(true, null, $set);
            return $set;
        } catch (Throwable $error) {
            $this->observe(false, $this->category($error), new StyleReferenceSet($shopProductId, null, [], 'stylitics', [
                'style_reference_cache_hit' => false,
                'style_reference_cache_ttl_seconds' => $ttl,
                'style_reference_total_ms' => $this->elapsed($started),
            ]));
            throw $error;
        }
    }

    private function cacheTtl(): int
    {
        $value = getenv('STYLITICS_CACHE_TTL');
        return $value === false || $value === '' ? 600 : max(0, min(3600, (int) $value));
    }

    /** @return array<string,mixed> */
    private function cacheValue(StyleReferenceSet $set): array
    {
        return [
            'occasion' => $set->occasion,
            'source_provider' => $set->sourceProvider,
            'references' => array_map(static fn (StyleReference $reference): array => $reference->toArray(), $set->references),
        ];
    }

    private function fromCache(mixed $value, int $shopProductId): ?StyleReferenceSet
    {
        if (!is_array($value) || !is_array($value['references'] ?? null)) return null;
        try {
            $references = [];
            foreach ($value['references'] as $reference) {
                if (!is_array($reference)) return null;
                $references[] = new StyleReference(
                    (string) ($reference['role'] ?? ''),
                    isset($reference['category']) ? (string) $reference['category'] : null,
                    isset($reference['subcategory']) ? (string) $reference['subcategory'] : null,
                    $this->stringList($reference['colors'] ?? []),
                    $this->stringList($reference['materials'] ?? []),
                    $this->stringList($reference['style_tags'] ?? []),
                    $this->stringList($reference['occasion_tags'] ?? []),
                    isset($reference['silhouette']) ? (string) $reference['silhouette'] : null,
                    isset($reference['reference_text']) ? (string) $reference['reference_text'] : null,
                    isset($reference['reference_image_url']) ? (string) $reference['reference_image_url'] : null,
                    (string) ($reference['source_provider'] ?? 'stylitics'),
                    isset($reference['source_reference_id']) ? (string) $reference['source_reference_id'] : null,
                    isset($reference['confidence']) ? (float) $reference['confidence'] : null
                );
            }
            return $references === [] ? null : new StyleReferenceSet(
                $shopProductId,
                isset($value['occasion']) ? (string) $value['occasion'] : null,
                $references,
                (string) ($value['source_provider'] ?? 'stylitics')
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) throw new InvalidArgumentException('Cached style reference list is invalid');
        foreach ($value as $item) if (!is_string($item) || trim($item) === '') throw new InvalidArgumentException('Cached style reference term is invalid');
        return array_values($value);
    }

    private function elapsed(float $started): int { return (int) round((microtime(true) - $started) * 1000); }

    private function category(Throwable $error): string
    {
        return $error instanceof StyliticsApiException ? $error->category : 'PROVIDER_UNAVAILABLE';
    }

    private function observe(bool $success, ?string $failureCategory, StyleReferenceSet $set): void
    {
        error_log(json_encode([
            'provider' => 'stylitics',
            'operation' => 'style_reference_generation',
            'success' => $success,
            'failure_category' => $failureCategory,
            'reference_count' => count($set->references),
        ] + $set->timings, JSON_UNESCAPED_SLASHES));
    }
}
