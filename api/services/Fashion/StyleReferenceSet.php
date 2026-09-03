<?php

final readonly class StyleReferenceSet
{
    /** @param list<StyleReference> $references */
    public function __construct(
        public int $anchorProductId,
        public ?string $occasion,
        public array $references,
        public string $sourceProvider = 'stylitics',
        /** @var array<string,int|bool> */
        public array $timings = []
    ) {
        if ($this->anchorProductId <= 0) throw new InvalidArgumentException('anchorProductId must be positive');
        foreach ($this->references as $reference) if (!$reference instanceof StyleReference) throw new InvalidArgumentException('references must contain StyleReference values');
    }
}
