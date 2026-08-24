<?php

final readonly class ComplementaryItemRequirement
{
    /** @param list<string> $styles @param list<string> $colors @param list<string> $materials */
    public function __construct(
        public string $category,
        public array $styles = [],
        public array $colors = [],
        public array $materials = [],
        public int $priority = 1,
        public ?string $subcategory = null
    ) {
        self::assertTerm($category, 'category');
        if ($subcategory !== null) {
            self::assertTerm($subcategory, 'subcategory');
        }
        if ($priority < 1 || $priority > 100) {
            throw new InvalidArgumentException('priority must be between 1 and 100');
        }
        foreach (['styles' => $styles, 'colors' => $colors, 'materials' => $materials] as $name => $terms) {
            if (count($terms) > 12) {
                throw new InvalidArgumentException("$name contains too many values");
            }
            foreach ($terms as $term) {
                if (!is_string($term)) {
                    throw new InvalidArgumentException("$name must contain strings");
                }
                self::assertTerm($term, $name);
            }
        }
    }

    public static function fromArray(array $data): self
    {
        foreach (['styles', 'colors', 'materials'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                throw new InvalidArgumentException("$field must be an array");
            }
        }
        return new self(
            trim((string) ($data['category'] ?? '')),
            self::cleanTerms($data['styles'] ?? []),
            self::cleanTerms($data['colors'] ?? []),
            self::cleanTerms($data['materials'] ?? []),
            (int) ($data['priority'] ?? 1),
            isset($data['subcategory']) ? trim((string) $data['subcategory']) : null
        );
    }

    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'styles' => $this->styles,
            'colors' => $this->colors,
            'materials' => $this->materials,
            'priority' => $this->priority,
        ];
    }

    private static function cleanTerms(array $terms): array
    {
        $clean = [];
        foreach ($terms as $term) {
            if (!is_string($term)) {
                throw new InvalidArgumentException('Requirement attributes must contain strings');
            }
            $term = trim($term);
            if ($term !== '') {
                $clean[] = $term;
            }
        }
        return array_values(array_unique($clean));
    }

    private static function assertTerm(string $term, string $field): void
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) > 80 || preg_match('/[\x00-\x1F\x7F]/', $term)) {
            throw new InvalidArgumentException("$field contains an invalid term");
        }
        if (!preg_match('/[\p{L}\p{N}]/u', $term)) {
            throw new InvalidArgumentException("$field contains a meaningless term");
        }
    }
}
