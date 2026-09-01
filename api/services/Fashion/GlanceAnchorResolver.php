<?php

require_once dirname(__DIR__, 2) . '/cache/Cache.php';

/** Resolves a private anchor to a provider-owned Glance catalog SKU. */
final class GlanceAnchorResolver implements GlanceAnchorResolverContract
{
    public function __construct(private PDO $pdo, private ?GlanceMcpClientContract $client = null) {}

    public function resolve(int $shopProductId, ?int $shopVariantId = null): GlanceAnchorReference
    {
        $product = $this->findProduct($shopProductId);
        if ($product === null) throw new GlanceAnchorResolutionException('ANCHOR_NOT_FOUND', 'Private anchor product was not found');
        $gender = $this->gender($product);
        if ($gender === null) {
            throw new GlanceAnchorResolutionException('GENDER_REQUIRED', 'Glance requires a catalog gender filter and the private anchor does not state one');
        }
        if ($this->client === null) {
            throw new GlanceAnchorResolutionException('GLANCE_SEARCH_REQUIRED', 'Glance search client is required to resolve a provider anchor');
        }
        $query = $this->searchQuery($product);
        $cacheKey = $this->cacheKey($product, $shopVariantId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $this->validCachedAnchor($cached)) {
            return new GlanceAnchorReference(
                $cached['provider_sku'],
                $cached['provider_reference_id'],
                $query,
                $gender,
                'smart-casual',
                [
                    'strategy' => 'glance_search_bridge',
                    'cache_hit' => true,
                    'private_anchor_id' => (int) $product['id'],
                    'variant_id' => $shopVariantId,
                    'provider_category' => $cached['provider_category'],
                ],
                (float) $cached['confidence']
            );
        }
        $result = $this->client->call('search_fashion_products', [
            'gender' => $gender,
            'country' => 'IN',
            'currency' => 'INR',
            'query' => $query,
            'context_summary' => 'Find a compatible reference for a private styling anchor.',
            'context_image_ref' => '',
            'occasion' => 'smart-casual',
        ]);
        $providerProduct = $this->nearestProviderProduct($result, $product);
        if ($providerProduct === null) {
            throw new GlanceAnchorResolutionException('NO_GLANCE_ANCHOR', 'Glance search returned no usable provider anchor');
        }
        $ttl = $this->cacheTtl();
        if ($ttl > 0) {
            Cache::set($cacheKey, [
                'provider_sku' => $providerProduct['sku'],
                'provider_reference_id' => $providerProduct['reference_id'],
                'provider_category' => $providerProduct['category'],
                'confidence' => $providerProduct['confidence'],
            ], $ttl);
        }
        return new GlanceAnchorReference(
            $providerProduct['sku'],
            $providerProduct['reference_id'],
            $query,
            $gender,
            'smart-casual',
            [
                'strategy' => 'glance_search_bridge',
                'cache_hit' => false,
                'private_anchor_id' => (int) $product['id'],
                'variant_id' => $shopVariantId,
                'provider_category' => $providerProduct['category'],
            ],
            $providerProduct['confidence']
        );
    }

    /** @param array<string,string|int> $product */
    private function cacheKey(array $product, ?int $shopVariantId): string
    {
        return 'glance-anchor:v1:' . hash('sha256', json_encode([
            'product_id' => (int) $product['id'],
            'variant_id' => $shopVariantId,
            'name' => (string) $product['name'],
            'description' => (string) $product['description'],
            'category' => (string) $product['category_name'],
            'subcategory' => (string) $product['subcategory_name'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<mixed> $cached */
    private function validCachedAnchor(array $cached): bool
    {
        return isset($cached['provider_sku'], $cached['provider_reference_id'], $cached['provider_category'], $cached['confidence'])
            && trim((string) $cached['provider_sku']) !== ''
            && trim((string) $cached['provider_reference_id']) !== ''
            && is_numeric($cached['confidence'])
            && (float) $cached['confidence'] >= 0
            && (float) $cached['confidence'] <= 1;
    }

    private function cacheTtl(): int
    {
        $value = getenv('GLANCE_ANCHOR_CACHE_TTL');
        return $value === false || $value === '' ? 86400 : max(0, (int) $value);
    }

    /** @param array<string,string|int> $product */
    private function searchQuery(array $product): string
    {
        return trim(implode(' ', array_filter([
            (string) $product['name'],
            (string) $product['category_name'],
            (string) $product['subcategory_name'],
            mb_substr((string) $product['description'], 0, 240),
        ])));
    }

    /** @param array<string,mixed> $result @param array<string,string|int> $anchor @return array{sku:string,reference_id:string,category:string,confidence:float}|null */
    private function nearestProviderProduct(array $result, array $anchor): ?array
    {
        $products = $result['structuredContent']['tiers'][0]['products'] ?? [];
        if (!is_array($products)) return null;
        $anchorTokens = $this->tokens(implode(' ', [(string) $anchor['name'], (string) $anchor['category_name'], (string) $anchor['subcategory_name']]));
        $best = null;
        foreach ($products as $product) {
            if (!is_array($product) || empty($product['in_stock']) || trim((string) ($product['sku'] ?? '')) === '') continue;
            $candidateTokens = $this->tokens(implode(' ', [(string) ($product['title'] ?? ''), (string) ($product['category'] ?? '')]));
            $overlap = count(array_intersect($anchorTokens, $candidateTokens));
            $score = min(1.0, 0.45 + (0.11 * $overlap));
            if ($best === null || $score > $best['confidence']) {
                $best = [
                    'sku' => trim((string) $product['sku']),
                    'reference_id' => trim((string) ($product['merchantVariantId'] ?? $product['sku'])),
                    'category' => trim((string) ($product['category'] ?? '')),
                    'confidence' => $score,
                ];
            }
        }
        return $best;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $normalized = ProductAttributeNormalizer::normalizeText($text);
        preg_match_all('/[a-z0-9]{3,}/u', $normalized, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }

    /** @return array<string,string|int>|null */
    private function findProduct(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT p.id, p.name, p.description, c.name AS category_name, sc.display_name AS subcategory_name
            FROM products p LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_subcategories sc ON sc.id = p.subcategory_id WHERE p.id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($product)) return null;
        foreach (['name', 'description', 'category_name', 'subcategory_name'] as $field) $product[$field] = trim((string) ($product[$field] ?? ''));
        $product['id'] = (int) $product['id'];
        return $product;
    }

    private function gender(array $product): ?string
    {
        $text = ProductAttributeNormalizer::normalizeText(implode(' ', [
            (string) $product['name'], (string) $product['description'], (string) $product['category_name'],
        ]));
        $male = preg_match('/\b(men|male|nam|quy ong)\b/u', $text) === 1;
        $female = preg_match('/\b(women|woman|female|nu|quy co)\b/u', $text) === 1;
        return $male === $female ? null : ($male ? 'MALE' : 'FEMALE');
    }
}
