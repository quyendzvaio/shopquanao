<?php

/** Private-catalog grounding result for one provider-neutral style reference. */
final readonly class MappedStyleReference
{
    /** @param list<array<string,mixed>> $candidates @param array<string,mixed> $evidence */
    public function __construct(
        public StyleReference $reference,
        public array $candidates,
        public ?array $selectedProduct,
        public float $mappingScore,
        public string $mappingStatus,
        public array $evidence = []
    ) {
        if (!in_array($mappingStatus, ['mapped', 'no_match', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid style mapping status');
        }
        if ($mappingScore < 0.0 || $mappingScore > 1.0) {
            throw new InvalidArgumentException('Style mapping score must be between 0 and 1');
        }
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || (int) ($candidate['id'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Mapped candidates must be private shop products');
            }
        }
        if ($selectedProduct !== null && (int) ($selectedProduct['id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Selected product must be a private shop product');
        }
    }

    public function toArray(): array
    {
        return [
            'reference' => $this->reference->toArray(),
            'candidates' => $this->candidates,
            'selected_product' => $this->selectedProduct,
            'mapping_score' => $this->mappingScore,
            'mapping_status' => $this->mappingStatus,
            'evidence' => $this->evidence,
        ];
    }
}
