<?php

final readonly class ComplementaryPlan
{
    /** @param list<ComplementaryItemRequirement> $requirements */
    /** @param list<array<string, mixed>> $providerItems */
    public function __construct(
        public int $anchorProductId,
        public array $requirements,
        public array $providerItems = [],
        public ?string $providerResponseUuid = null
    )
    {
        if ($anchorProductId <= 0) {
            throw new InvalidArgumentException('anchorProductId must be positive');
        }
        if ($requirements === [] || count($requirements) > 12) {
            throw new InvalidArgumentException('requirements must contain between 1 and 12 items');
        }
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof ComplementaryItemRequirement) {
                throw new InvalidArgumentException('requirements contains an invalid item');
            }
        }
        foreach ($providerItems as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('providerItems contains an invalid item');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['requirements']) || !is_array($data['requirements'])) {
            throw new InvalidArgumentException('requirements must be an array');
        }
        $requirements = array_map(
            fn ($item) => is_array($item)
                ? ComplementaryItemRequirement::fromArray($item)
                : throw new InvalidArgumentException('requirements contains an invalid item'),
            $data['requirements']
        );
        usort($requirements, fn ($a, $b) => $a->priority <=> $b->priority);
        return new self(
            (int) ($data['anchor_product_id'] ?? 0),
            $requirements,
            is_array($data['provider_items'] ?? null) ? array_values($data['provider_items']) : [],
            isset($data['provider_response_uuid']) ? (string) $data['provider_response_uuid'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'anchor_product_id' => $this->anchorProductId,
            'requirements' => array_map(fn ($item) => $item->toArray(), $this->requirements),
            'provider_items' => $this->providerItems,
            'provider_response_uuid' => $this->providerResponseUuid,
        ];
    }
}
