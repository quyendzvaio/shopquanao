<?php

/** Live Glance edge: private anchor metadata -> query-mode bridge -> StyleReferenceSet. */
final class GlanceStylingProvider implements StylingReferenceProvider
{
    public function __construct(
        private GlanceConfig $config,
        private ?GlanceAnchorResolverContract $anchors = null,
        private ?GlanceMcpClientContract $client = null,
        private ?GlanceLiveResponseMapper $mapper = null
    ) {}

    public function referencesForAnchor(int $shopProductId, ?int $shopVariantId = null): StyleReferenceSet
    {
        if (!$this->config->enabled || $this->config->mode !== 'live') throw new RuntimeException('Glance live provider is not enabled');
        if ($this->config->mcpUrl === '' || $this->config->toolName !== 'get_mix_and_match') throw new RuntimeException('Glance MCP URL and verified styling tool are required');
        if ($this->anchors === null) throw new RuntimeException('Glance private anchor resolver is required');
        $started = microtime(true);
        $ttl = $this->cacheTtl();
        try {
            $anchorStarted = microtime(true);
            $anchor = $this->anchors->resolve($shopProductId, $shopVariantId);
            $anchorMs = $this->elapsed($anchorStarted);
            // The anchor resolver has its own long cache. Resolving it first
            // makes the short-lived reference cache safe across query/anchor
            // strategy changes while still avoiding a second Glance mix call.
            $cacheKey = $this->cacheKey($shopProductId, $shopVariantId, $anchor);
            $cached = $ttl > 0 ? $this->fromCache(Cache::get($cacheKey), $shopProductId) : null;
            if ($cached !== null) {
                $timings = $cached->timings + [
                    'style_reference_cache_hit' => true,
                    'style_reference_cache_ttl_seconds' => $ttl,
                    'glance_anchor_resolution_ms' => $anchorMs,
                    'style_reference_total_ms' => $this->elapsed($started),
                ];
                $set = new StyleReferenceSet($cached->anchorProductId, $cached->occasion, $cached->references, $cached->sourceProvider, $timings);
                $this->observe(true, null, $set);
                return $set;
            }
            $mixStarted = microtime(true);
            $raw = ($this->client ?? new GlanceMcpClient($this->config))->call($this->config->toolName, [
                'anchor_sku' => $anchor->providerSku ?? '',
                // The verified Glance contract gives query precedence over anchor_sku.
                'query' => $anchor->providerSku === null ? $anchor->query : '',
                'context_image_ref' => '',
                'gender' => $anchor->gender,
                'occasion' => $anchor->occasion,
            ]);
            $mixMs = $this->elapsed($mixStarted);
            $mappingStarted = microtime(true);
            $references = ($this->mapper ?? new GlanceLiveResponseMapper())->map($raw);
            $timings = [
                'style_reference_cache_hit' => false,
                'style_reference_cache_ttl_seconds' => $ttl,
                'glance_anchor_resolution_ms' => $anchorMs,
                'glance_mix_and_match_ms' => $mixMs,
                'glance_response_mapping_ms' => $this->elapsed($mappingStarted),
                'style_reference_total_ms' => $this->elapsed($started),
            ];
            $set = new StyleReferenceSet($shopProductId, $anchor->occasion, $references, 'glance', $timings);
            if ($ttl > 0 && $references !== []) Cache::set($cacheKey, $this->cacheValue($set), $ttl);
            $this->observe(true, null, $set);
            return $set;
        } catch (Throwable $error) {
            $this->observe(false, $this->category($error), new StyleReferenceSet($shopProductId, null, [], 'glance', [
                'style_reference_cache_hit' => false,
                'style_reference_cache_ttl_seconds' => $ttl,
                'style_reference_total_ms' => $this->elapsed($started),
            ]));
            throw $error;
        }
    }

    private function cacheTtl(): int
    {
        $value = getenv('GLANCE_STYLE_REFERENCE_CACHE_TTL');
        return $value === false || $value === '' ? 600 : max(0, min(3600, (int) $value));
    }

    private function cacheKey(int $shopProductId, ?int $shopVariantId, GlanceAnchorReference $anchor): string
    {
        return 'glance-style-reference:v1:' . hash('sha256', json_encode([
            'product_id' => $shopProductId,
            'variant_id' => $shopVariantId,
            'mcp_url' => $this->config->mcpUrl,
            'tool' => $this->config->toolName,
            'provider_sku' => $anchor->providerSku,
            'query' => $anchor->providerSku === null ? $anchor->query : '',
            'gender' => $anchor->gender,
            'occasion' => $anchor->occasion,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
                    (string) ($reference['source_provider'] ?? 'glance'),
                    isset($reference['source_reference_id']) ? (string) $reference['source_reference_id'] : null,
                    isset($reference['confidence']) ? (float) $reference['confidence'] : null,
                );
            }
            return $references === [] ? null : new StyleReferenceSet(
                $shopProductId,
                isset($value['occasion']) ? (string) $value['occasion'] : null,
                $references,
                (string) ($value['source_provider'] ?? 'glance')
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
        return $error instanceof GlanceMcpException ? $error->category : 'PROVIDER_UNAVAILABLE';
    }

    private function observe(bool $success, ?string $failureCategory, StyleReferenceSet $set): void
    {
        error_log(json_encode([
            'provider' => 'glance',
            'operation' => 'style_reference_generation',
            'success' => $success,
            'failure_category' => $failureCategory,
            'reference_count' => count($set->references),
        ] + $set->timings, JSON_UNESCAPED_SLASHES));
    }
}
