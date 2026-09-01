<?php

use PHPUnit\Framework\TestCase;

final class GlanceStylingProviderTest extends TestCase
{
    public function testDemoReferencesAreNotShopProductsAndPreserveRoles(): void
    {
        $set = (new GlanceDemoStylingProvider())->referencesForAnchor(50);
        self::assertSame('glance_demo', $set->sourceProvider);
        self::assertSame(['bottom', 'shoe', 'accessory'], array_map(fn (StyleReference $r): string => $r->role, $set->references));
        self::assertSame('brown leather loafers', $set->references[1]->referenceText);
        self::assertStringNotContainsString('product_id', json_encode($set->references, JSON_THROW_ON_ERROR));
    }

    public function testLiveProviderFailsClosedWithoutVendorContract(): void
    {
        $provider = new GlanceStylingProvider(new GlanceConfig(true, 'live', 'https://glance.invalid/mcp', '', false));
        $this->expectException(RuntimeException::class);
        $provider->referencesForAnchor(50);
    }

    public function testAdapterProducesReferenceTextWithoutProviderIds(): void
    {
        $suggestions = (new StyleReferenceRawSuggestionAdapter(new GlanceDemoStylingProvider()))->suggestForAnchor(50);
        self::assertCount(3, $suggestions);
        self::assertSame('glance_demo', $suggestions[0]->source);
        self::assertStringNotContainsString('product_id', json_encode($suggestions, JSON_THROW_ON_ERROR));
    }

    public function testLiveProviderUsesQueryMetadataBridgeAndStructuredMapper(): void
    {
        $raw = json_decode((string) file_get_contents(ROOT_DIR . '/tests/fixtures/glance/mapper-live-shape.json'), true, 512, JSON_THROW_ON_ERROR);
        $client = new RecordingGlanceMcpClient($raw);
        $provider = new GlanceStylingProvider(
            new GlanceConfig(true, 'live', 'https://ember.ailooks.glance.com/mcp', 'get_mix_and_match', false),
            new FakeGlanceAnchorResolver(),
            $client,
            new GlanceLiveResponseMapper()
        );

        $set = $provider->referencesForAnchor(57);
        self::assertSame('glance', $set->sourceProvider);
        self::assertCount(2, $set->references);
        self::assertSame('', $client->arguments['anchor_sku']);
        self::assertSame('style a private anchor', $client->arguments['query']);
        self::assertSame('MALE', $client->arguments['gender']);
        self::assertSame('provider-sku-bottom', $set->references[0]->sourceReferenceId);
    }

    public function testLiveProviderUsesResolvedProviderSkuWithoutQueryOverride(): void
    {
        $raw = json_decode((string) file_get_contents(ROOT_DIR . '/tests/fixtures/glance/mapper-live-shape.json'), true, 512, JSON_THROW_ON_ERROR);
        $client = new RecordingGlanceMcpClient($raw);
        $provider = new GlanceStylingProvider(
            new GlanceConfig(true, 'live', 'https://ember.ailooks.glance.com/mcp', 'get_mix_and_match', false),
            new FakeDirectGlanceAnchorResolver(),
            $client,
            new GlanceLiveResponseMapper()
        );

        $provider->referencesForAnchor(57);
        self::assertSame('glance-anchor-sku', $client->arguments['anchor_sku']);
        self::assertSame('', $client->arguments['query']);
    }

    public function testLiveProviderCachesNormalizedReferencesForTheShortStyleTtl(): void
    {
        putenv('GLANCE_STYLE_REFERENCE_CACHE_TTL=600');
        $raw = json_decode((string) file_get_contents(ROOT_DIR . '/tests/fixtures/glance/mapper-live-shape.json'), true, 512, JSON_THROW_ON_ERROR);
        $client = new CountingMixAndMatchClient($raw);
        $provider = new GlanceStylingProvider(
            new GlanceConfig(true, 'live', 'https://ember.ailooks.glance.com/mcp', 'get_mix_and_match', false),
            new FakeDirectGlanceAnchorResolver(),
            $client,
            new GlanceLiveResponseMapper()
        );

        $first = $provider->referencesForAnchor(987654, 10);
        $second = $provider->referencesForAnchor(987654, 10);

        self::assertSame(1, $client->callCount);
        self::assertCount(2, $second->references);
        self::assertSame(600, $second->timings['style_reference_cache_ttl_seconds']);
        self::assertFalse((bool) $first->timings['style_reference_cache_hit']);
        self::assertTrue((bool) $second->timings['style_reference_cache_hit']);
        putenv('GLANCE_STYLE_REFERENCE_CACHE_TTL');
    }

    public function testAnchorSearchBridgeCachesOnlySafeProviderAnchorMetadata(): void
    {
        putenv('GLANCE_ANCHOR_CACHE_TTL=3600');
        $pdo = getTestPDO();
        $pdo->prepare('UPDATE products SET description = ? WHERE id = 50')->execute(['Men male white cotton shirt']);
        $client = new CountingAnchorSearchClient();
        $resolver = new GlanceAnchorResolver($pdo, $client);

        $first = $resolver->resolve(50);
        $second = $resolver->resolve(50);

        self::assertSame(1, $client->callCount);
        self::assertSame('provider-anchor-sku', $first->providerSku);
        self::assertSame($first->providerSku, $second->providerSku);
        self::assertFalse((bool) $first->evidence['cache_hit']);
        self::assertTrue((bool) $second->evidence['cache_hit']);
        self::assertArrayNotHasKey('access_token', $second->evidence);
        putenv('GLANCE_ANCHOR_CACHE_TTL');
    }
}

final class FakeGlanceAnchorResolver implements GlanceAnchorResolverContract
{
    public function resolve(int $shopProductId, ?int $shopVariantId = null): GlanceAnchorReference
    {
        return new GlanceAnchorReference(null, null, 'style a private anchor', 'MALE', 'smart-casual', ['strategy' => 'query_metadata_bridge'], 0.6);
    }
}

final class FakeDirectGlanceAnchorResolver implements GlanceAnchorResolverContract
{
    public function resolve(int $shopProductId, ?int $shopVariantId = null): GlanceAnchorReference
    {
        return new GlanceAnchorReference('glance-anchor-sku', 'glance-reference', 'must not override direct anchor', 'MALE', 'smart-casual', ['strategy' => 'glance_search_bridge'], 0.8);
    }
}

final class RecordingGlanceMcpClient implements GlanceMcpClientContract
{
    /** @var array<string,mixed> */
    public array $arguments = [];
    /** @param array<string,mixed> $result */
    public function __construct(private array $result) {}
    public function call(string $toolName, array $arguments): array
    {
        $this->arguments = $arguments;
        return $this->result;
    }
}

final class CountingAnchorSearchClient implements GlanceMcpClientContract
{
    public int $callCount = 0;

    public function call(string $toolName, array $arguments): array
    {
        ++$this->callCount;
        if ($toolName !== 'search_fashion_products') throw new RuntimeException('Unexpected tool');
        return [
            'structuredContent' => [
                'tiers' => [[
                    'products' => [[
                        'sku' => 'provider-anchor-sku',
                        'merchantVariantId' => 'provider-anchor-reference',
                        'category' => 'Topwear',
                        'in_stock' => true,
                    ]],
                ]],
            ],
        ];
    }
}

final class CountingMixAndMatchClient implements GlanceMcpClientContract
{
    public int $callCount = 0;

    /** @param array<string,mixed> $result */
    public function __construct(private array $result) {}

    public function call(string $toolName, array $arguments): array
    {
        ++$this->callCount;
        if ($toolName !== 'get_mix_and_match') throw new RuntimeException('Unexpected tool');
        return $this->result;
    }
}
