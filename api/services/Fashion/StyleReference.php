<?php

/** A normalized external styling reference; it is not a private shop SKU. */
final readonly class StyleReference
{
    /** @param list<string> $colors @param list<string> $materials @param list<string> $styleTags @param list<string> $occasionTags */
    public function __construct(
        public string $role,
        public ?string $category,
        public ?string $subcategory = null,
        public array $colors = [],
        public array $materials = [],
        public array $styleTags = [],
        public array $occasionTags = [],
        public ?string $silhouette = null,
        public ?string $referenceText = null,
        public ?string $referenceImageUrl = null,
        public string $sourceProvider = 'glance',
        public ?string $sourceReferenceId = null,
        public ?float $confidence = null
    ) {
        if (trim($this->role) === '') throw new InvalidArgumentException('Style reference role is required');
        foreach ([$this->colors, $this->materials, $this->styleTags, $this->occasionTags] as $values) {
            foreach ($values as $value) if (!is_string($value) || trim($value) === '') throw new InvalidArgumentException('Style reference terms must be non-empty strings');
        }
    }

    public function toArray(): array
    {
        return [
            'role' => $this->role, 'category' => $this->category, 'subcategory' => $this->subcategory,
            'colors' => $this->colors, 'materials' => $this->materials, 'style_tags' => $this->styleTags,
            'occasion_tags' => $this->occasionTags, 'silhouette' => $this->silhouette,
            'reference_text' => $this->referenceText, 'reference_image_url' => $this->referenceImageUrl,
            'source_provider' => $this->sourceProvider, 'source_reference_id' => $this->sourceReferenceId,
            'confidence' => $this->confidence,
        ];
    }
}
