<?php

/** Provider-only anchor metadata. A null provider SKU means query-mode bridge. */
final readonly class GlanceAnchorReference
{
    /** @param array<string,mixed> $evidence */
    public function __construct(
        public ?string $providerSku,
        public ?string $providerReferenceId,
        public string $query,
        public string $gender,
        public ?string $occasion,
        public array $evidence,
        public float $confidence
    ) {
        if ($query === '' || !in_array($gender, ['MALE', 'FEMALE'], true) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Invalid Glance anchor reference');
        }
    }
}
