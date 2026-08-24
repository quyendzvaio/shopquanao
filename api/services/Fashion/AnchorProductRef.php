<?php

final readonly class AnchorProductRef
{
    public function __construct(
        public int $shopProductId,
        public string $provider,
        public string $providerProductId,
        public ?int $shopVariantId = null,
        public ?string $providerVariantId = null,
        public ?string $providerColorId = null
    ) {
        if ($shopProductId <= 0) {
            throw new InvalidArgumentException('shopProductId must be positive');
        }
        self::assertIdentifier($provider, 'provider');
        self::assertIdentifier($providerProductId, 'providerProductId');
        if ($shopVariantId !== null && $shopVariantId <= 0) {
            throw new InvalidArgumentException('shopVariantId must be positive when supplied');
        }
        foreach (['providerVariantId' => $providerVariantId, 'providerColorId' => $providerColorId] as $name => $value) {
            if ($value !== null) {
                self::assertIdentifier($value, $name);
            }
        }
    }

    public function toArray(): array
    {
        return [
            'shop_product_id' => $this->shopProductId,
            'shop_variant_id' => $this->shopVariantId,
            'provider' => $this->provider,
            'provider_product_id' => $this->providerProductId,
            'provider_variant_id' => $this->providerVariantId,
            'provider_color_id' => $this->providerColorId,
        ];
    }

    private static function assertIdentifier(string $value, string $field): void
    {
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 191 || preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
            throw new InvalidArgumentException("$field is invalid");
        }
    }
}
