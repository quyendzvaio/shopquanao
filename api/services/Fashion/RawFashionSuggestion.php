<?php

final readonly class RawFashionSuggestion
{
    /** @param array<string,mixed> $providerContext */
    public function __construct(
        public string $text,
        public string $source = 'findmine_demo',
        public array $providerContext = []
    ) {
        if (trim($this->text) === '') {
            throw new InvalidArgumentException('Raw fashion suggestion text is required');
        }
        if ($this->source !== 'findmine_demo') {
            throw new InvalidArgumentException('Unsupported raw fashion suggestion source');
        }
    }
}
