<?php

final class StyliticsStylingProviderTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        foreach (['STYLITICS_CACHE_TTL', 'STYLITICS_PROVIDER_MODE'] as $key) {
            putenv($key);
        }
        parent::tearDown();
    }

    private function liveConfig(): StyliticsConfig
    {
        return new StyliticsConfig(true, 'live', 'https://staging.example.com/complete-the-look', 'staging-key', true);
    }

    public function testReturnsMappedReferencesFromTheApiClient(): void
    {
        putenv('STYLITICS_CACHE_TTL=0');
        $client = new RecordingStyliticsClient($this->fixtureRaw());
        $provider = new StyliticsStylingProvider($this->liveConfig(), new FakeStyliticsSkuResolver('SHOP-TR-01'), $client);

        $set = $provider->referencesForAnchor(57);

        self::assertSame('SHOP-TR-01', $client->lastAnchorSku);
        self::assertSame('stylitics', $set->sourceProvider);
        self::assertSame('office', $set->occasion);
        self::assertCount(3, $set->references);
        self::assertFalse($set->timings['style_reference_cache_hit']);
    }

    public function testFailsClosedWhenNotLiveEnabled(): void
    {
        $config = new StyliticsConfig(true, 'demo', 'https://staging.example.com/complete-the-look', 'staging-key', false);
        $provider = new StyliticsStylingProvider($config, new FakeStyliticsSkuResolver('SHOP-TR-01'));
        $this->expectException(StyliticsApiException::class);
        $provider->referencesForAnchor(57);
    }

    public function testFailsClosedWhenSkuResolverIsMissing(): void
    {
        putenv('STYLITICS_CACHE_TTL=0');
        $provider = new StyliticsStylingProvider($this->liveConfig());
        $this->expectException(StyliticsApiException::class);
        $provider->referencesForAnchor(57);
    }

    public function testPropagatesApiErrorsAsProviderFailures(): void
    {
        putenv('STYLITICS_CACHE_TTL=0');
        $provider = new StyliticsStylingProvider(
            $this->liveConfig(),
            new FakeStyliticsSkuResolver('SHOP-TR-01'),
            new FailingStyliticsClient()
        );
        try {
            $provider->referencesForAnchor(57);
            self::fail('expected provider failure to propagate');
        } catch (StyliticsApiException $error) {
            self::assertSame('AUTH_ERROR', $error->category);
        }
    }

    /** @return array<string,mixed> */
    private function fixtureRaw(): array
    {
        return json_decode((string) file_get_contents(TEST_DIR . '/fixtures/stylitics/complete-the-look-response.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class RecordingStyliticsClient implements StyliticsHttpClientContract
{
    public string $lastAnchorSku = '';

    /** @param array<string,mixed> $raw */
    public function __construct(private array $raw) {}

    public function completeTheLook(string $anchorSku, ?string $anchorVariantSku = null): array
    {
        $this->lastAnchorSku = $anchorSku;
        return $this->raw;
    }
}

final class FailingStyliticsClient implements StyliticsHttpClientContract
{
    public function completeTheLook(string $anchorSku, ?string $anchorVariantSku = null): array
    {
        throw new StyliticsApiException('AUTH_ERROR', 'Stylitics HTTP 401');
    }
}

final class FakeStyliticsSkuResolver implements StyliticsAnchorSkuResolverContract
{
    public function __construct(private string $sku) {}

    public function resolveSku(int $shopProductId, ?int $shopVariantId = null): string
    {
        return $this->sku;
    }
}
