<?php

final class FindMineFashionProvider implements FashionProvider
{
    public function __construct(
        private FashionProviderMappingRepository $mappings,
        private FindMineMcpClientContract $client,
        private FindMineV3ResponseAdapter $adapter = new FindMineV3ResponseAdapter(),
        private string $provider = 'findmine',
        private int $retryAttempts = 1,
        private ?FindMineConfig $config = null
    ) {
        $this->retryAttempts = max(0, min(3, $this->retryAttempts));
    }

    public function completeTheLook(AnchorProductRef $anchor): FashionProviderResult
    {
        if ($anchor->provider !== $this->provider) {
            return FashionProviderResult::failure('mapping_not_found', 'Provider mapping does not belong to FindMine');
        }
        $mapping = $this->mappings->findSynced($this->provider, $anchor->shopProductId, $anchor->shopVariantId);
        if ($mapping === null) {
            return FashionProviderResult::failure('mapping_not_found', 'No synced FindMine mapping exists for this anchor');
        }

        $config = $this->config ?? FindMineConfig::fromEnvironment();
        $arguments = [
            $config->productIdentifierKey => $mapping->providerProductId,
            'in_stock' => true,
            'on_sale' => false,
            'return_pdp_item' => true,
        ];
        if ($mapping->providerColorId !== null) $arguments[$config->colorIdentifierKey] = $mapping->providerColorId;
        $arguments['product_identifiers'] = $mapping->providerIdentifiers !== []
            ? $mapping->providerIdentifiers
            : array_filter([
                $config->productIdentifierKey => $mapping->providerProductId,
                $config->colorIdentifierKey => $mapping->providerColorId,
            ], fn ($value) => $value !== null && $value !== '');

        $started = microtime(true);
        $attempt = 0;
        do {
            try {
                $raw = $this->client->call('get_complete_the_look', $arguments);
                $plan = $this->adapter->toPlan($raw, $anchor->shopProductId);
                $this->observe(true, null, $started, count($plan->requirements));
                return FashionProviderResult::success($plan);
            } catch (FindMineProviderException $error) {
                $attempt++;
                if (!$error->retryable || $attempt > $this->retryAttempts) {
                    $this->observe(false, $error->category, $started, 0);
                    return FashionProviderResult::failure($this->domainCode($error->category), $this->safeMessage($error), $error->retryable);
                }
            } catch (Throwable $error) {
                $this->observe(false, 'PROVIDER_UNAVAILABLE', $started, 0);
                return FashionProviderResult::failure('provider_unavailable', 'FindMine is temporarily unavailable', true);
            }
        } while ($attempt <= $this->retryAttempts);

        return FashionProviderResult::failure('provider_unavailable', 'FindMine is temporarily unavailable', true);
    }

    private function domainCode(string $category): string
    {
        return match ($category) {
            'AUTHENTICATION_ERROR' => 'authentication_failed',
            'PROVIDER_TIMEOUT' => 'timeout',
            'UNKNOWN_PROVIDER_PRODUCT' => 'unknown_provider_product',
            'INVALID_REQUEST' => 'invalid_request',
            'RATE_LIMITED' => 'rate_limited',
            'EMPTY_RECOMMENDATION' => 'empty_recommendation',
            'INVALID_PROVIDER_RESPONSE' => 'invalid_response',
            default => 'provider_unavailable',
        };
    }

    private function safeMessage(FindMineProviderException $error): string
    {
        return match ($error->category) {
            'AUTHENTICATION_ERROR' => 'FindMine authentication is not valid',
            'PROVIDER_TIMEOUT' => 'FindMine request timed out',
            'UNKNOWN_PROVIDER_PRODUCT' => 'FindMine does not recognize the mapped product',
            'EMPTY_RECOMMENDATION' => 'FindMine returned no usable recommendations',
            'INVALID_PROVIDER_RESPONSE' => 'FindMine returned an invalid recommendation response',
            'INVALID_REQUEST' => 'FindMine rejected the product identifiers',
            'RATE_LIMITED' => 'FindMine rate limit was reached',
            default => 'FindMine is temporarily unavailable',
        };
    }

    private function observe(bool $success, ?string $error, float $started, int $requirementCount): void
    {
        error_log(json_encode([
            'provider' => 'findmine',
            'operation' => 'complete_the_look',
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'success' => $success,
            'error_category' => $error,
            'requirement_count' => $requirementCount,
        ], JSON_UNESCAPED_SLASHES));
    }
}
