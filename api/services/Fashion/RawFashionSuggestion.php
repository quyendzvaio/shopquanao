<?php

final readonly class RawFashionSuggestion
{
    /** @param array<string,mixed> $providerContext */
    public function __construct(
        public string $text,
        public string $source = 'glance_demo',
        public array $providerContext = []
    ) {
        if (trim($this->text) === '') {
            throw new InvalidArgumentException('Raw fashion suggestion text is required');
        }
        if (!in_array($this->source, ['glance', 'glance_demo', 'findmine_demo'], true)) {
            throw new InvalidArgumentException('Unsupported raw fashion suggestion source');
        }
    }
}
