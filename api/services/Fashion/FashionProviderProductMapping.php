<?php

final readonly class FashionProviderProductMapping
{
    public function __construct(
        public int $shopProductId,
        public string $provider,
        public string $providerProductId,
        public ?int $shopVariantId = null,
        public ?string $providerVariantId = null,
        public ?string $providerColorId = null,
        public string $syncStatus = 'pending',
        public ?string $syncVersion = null,
        public ?string $lastSyncedAt = null,
        public ?string $lastError = null,
        public array $providerIdentifiers = []
    ) {
        if ($shopProductId <= 0) {
            throw new InvalidArgumentException('shopProductId must be positive');
        }
        if ($shopVariantId !== null && $shopVariantId <= 0) {
            throw new InvalidArgumentException('shopVariantId must be positive when supplied');
        }
        if (!in_array($syncStatus, ['pending', 'synced', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid mapping sync status');
        }
        foreach (['provider' => $provider, 'providerProductId' => $providerProductId] as $field => $value) {
            if (trim($value) === '' || strlen($value) > 191) {
                throw new InvalidArgumentException("$field is invalid");
            }
        }
        foreach ($providerIdentifiers as $key => $value) {
            if (!is_string($key) || !preg_match('/^product_[a-z0-9_]+$/', $key) || !is_scalar($value) || trim((string) $value) === '') {
                throw new InvalidArgumentException('Provider identifiers must be non-empty product_* string values');
            }
        }
    }

    public function toAnchor(): AnchorProductRef
    {
        if ($this->syncStatus !== 'synced') {
            throw new LogicException('Only a synced mapping can be used as a fashion anchor');
        }
        return new AnchorProductRef(
            $this->shopProductId,
            $this->provider,
            $this->providerProductId,
            $this->shopVariantId,
            $this->providerVariantId,
            $this->providerColorId
        );
    }
}
