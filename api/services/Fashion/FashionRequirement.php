<?php

/** Validated, normalized Product Search requirement. */
readonly class FashionRequirement
{
    /**
     * @param list<string> $styles
     * @param list<string> $colors
     * @param list<string> $materials
     * @param list<string> $patterns
     * @param list<string> $fits
     */
    public function __construct(
        public int $sourcePriority,
        public string $sourceCategory,
        public string $search,
        public ?int $categoryId,
        public array $styles,
        public array $colors,
        public array $materials,
        public ?string $canonicalCategory = null,
        public ?string $subcategory = null,
        public array $patterns = [],
        public array $fits = [],
        public bool $textFallback = false
    ) {
        if ($sourcePriority < 1 || trim($sourceCategory) === '' || trim($search) === '') {
            throw new InvalidArgumentException('Invalid normalized fashion requirement');
        }
        if ($canonicalCategory !== 'footwear' && $subcategory !== null) {
            throw new InvalidArgumentException('A subcategory requires a compatible canonical category');
        }
        foreach ([$styles, $colors, $materials, $patterns, $fits] as $values) {
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException('Fashion requirement attributes must be non-empty strings');
                }
            }
        }
    }

    public function searchArguments(): array
    {
        return $this->searchAttempts()[0];
    }

    /** @return list<array<string,mixed>> ordered from strict to safely relaxed */
    public function searchAttempts(): array
    {
        $base = ['search' => $this->search, 'in_stock' => true];
        if ($this->categoryId !== null) $base['category_id'] = $this->categoryId;
        if ($this->canonicalCategory !== null) $base['category'] = $this->canonicalCategory;
        if ($this->subcategory !== null) $base['subcategory'] = $this->subcategory;

        $strict = $base;
        if ($this->colors !== []) $strict['color'] = $this->colors[0];
        if ($this->styles !== []) $strict['style'] = $this->styles;
        if ($this->materials !== []) $strict['material'] = $this->materials;
        $soft = array_values(array_unique(array_merge($this->patterns, $this->fits)));
        if ($soft !== []) $strict['semantic_query'] = implode(' ', $soft);

        $attempts = [$strict];
        $withoutSoft = $strict;
        unset($withoutSoft['style'], $withoutSoft['semantic_query']);
        $attempts[] = $withoutSoft;
        if ($this->colors !== []) {
            $color = $base;
            $color['color'] = $this->colors[0];
            $attempts[] = $color;
        }
        if ($this->materials !== []) {
            $material = $base;
            $material['material'] = $this->materials;
            $attempts[] = $material;
        }
        $attempts[] = $base;

        $unique = [];
        foreach ($attempts as $attempt) {
            $key = json_encode($attempt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $unique[$key] = $attempt;
        }
        return array_values($unique);
    }

    public function key(): string
    {
        return $this->sourcePriority . ':' . ProductAttributeNormalizer::normalizeText($this->sourceCategory);
    }
}
