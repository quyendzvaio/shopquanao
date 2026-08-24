<?php

final readonly class ExtractedFashionItem
{
    public function __construct(
        public ?string $category,
        public ?string $subcategory,
        public ?string $color,
        public ?string $material,
        public ?string $style,
        public ?string $pattern,
        public ?string $fit
    ) {
        foreach ($this->toArray() as $name => $value) {
            if ($value !== null && (trim($value) === '' || mb_strlen($value) > 100)) {
                throw new InvalidArgumentException("Invalid extracted fashion attribute: {$name}");
            }
        }
    }

    /** @param array<string,mixed> $item */
    public static function fromArray(array $item): self
    {
        $fields = ['category', 'subcategory', 'color', 'material', 'style', 'pattern', 'fit'];
        $keys = array_keys($item);
        sort($keys);
        $expected = $fields;
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Extracted fashion item has unknown or missing fields');
        }
        foreach ($fields as $field) {
            if ($item[$field] !== null && !is_string($item[$field])) {
                throw new InvalidArgumentException("Extracted fashion attribute {$field} must be string or null");
            }
        }
        return new self(...array_map(
            static fn (string $field): ?string => $item[$field] === null ? null : trim($item[$field]),
            $fields
        ));
    }

    /** @return array{category:?string,subcategory:?string,color:?string,material:?string,style:?string,pattern:?string,fit:?string} */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'color' => $this->color,
            'material' => $this->material,
            'style' => $this->style,
            'pattern' => $this->pattern,
            'fit' => $this->fit,
        ];
    }
}
