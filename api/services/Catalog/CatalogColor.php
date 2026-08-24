<?php

final readonly class CatalogColor
{
    public function __construct(
        public int $id,
        public string $canonical,
        public string $displayName,
        public ?string $externalCode = null
    ) {
        if ($id <= 0 || trim($canonical) === '' || trim($displayName) === '') {
            throw new InvalidArgumentException('Invalid catalog color');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'canonical' => $this->canonical,
            'display_name' => $this->displayName,
            'external_code' => $this->externalCode,
        ];
    }
}
