<?php

final class FashionRequirementNormalizer
{
    public const VERSION = '1';

    public function __construct(
        private FashionPipelineMetrics $metrics = new StructuredLogFashionMetrics(),
        private FashionTaxonomyNormalizer $taxonomy = new FashionTaxonomyNormalizer()
    ) {}

    /** @param list<ExtractedFashionItem> $items @return list<FashionRequirement> */
    public function normalize(array $items): array
    {
        $requirements = [];
        foreach ($items as $index => $item) {
            if (!$item instanceof ExtractedFashionItem) {
                throw new InvalidArgumentException('Normalizer accepts only ExtractedFashionItem values');
            }
            $requirement = $this->normalizeItem($item, $index + 1);
            if ($requirement === null) {
                $this->metrics->increment('fashion_normalization_unknown_category_total');
                continue;
            }
            $requirements[] = $requirement;
            $this->metrics->increment('fashion_normalization_success_total');
        }
        return $requirements;
    }

    private function normalizeItem(ExtractedFashionItem $item, int $priority): ?FashionRequirement
    {
        $category = trim((string) $item->category);
        if ($category === '') return null;

        $subcategoryIsFootwear = CatalogTaxonomy::normalizeFootwearSubcategory($item->subcategory);
        $categoryIsFootwear = CatalogTaxonomy::normalizeFootwearSubcategory($category);
        $categoryOnly = $this->taxonomy->normalize(new ComplementaryItemRequirement($category));
        if ($subcategoryIsFootwear !== null && $categoryIsFootwear === null && $categoryOnly !== null) {
            return null;
        }

        $taxonomyCategory = $category;
        if (in_array(ProductAttributeNormalizer::normalizeText($category), ['accessories', 'accessory'], true)) {
            $knownAccessory = match (ProductAttributeNormalizer::normalizeText((string) $item->subcategory)) {
                'belt', 'belts' => 'belt',
                'bag', 'bags', 'handbag', 'crossbody bag' => 'bag',
                'sunglasses', 'sunglass' => 'sunglasses',
                'watch', 'watches' => 'watch',
                'hat', 'hats', 'cap', 'caps' => 'hat',
                default => null,
            };
            if ($knownAccessory !== null) $taxonomyCategory = $knownAccessory;
        }

        $providerRequirement = new ComplementaryItemRequirement(
            $taxonomyCategory,
            array_values(array_filter([$item->style, $item->fit])),
            array_values(array_filter([$item->color])),
            array_values(array_filter([$item->material])),
            $priority,
            $item->subcategory
        );
        $normalized = $this->taxonomy->normalize($providerRequirement);
        if ($normalized !== null) {
            return new FashionRequirement(
                $priority,
                $category,
                $normalized->search,
                $normalized->categoryId,
                $normalized->styles,
                $normalized->colors,
                $normalized->materials,
                $normalized->canonicalCategory,
                $normalized->subcategory,
                $this->normalizePatterns($item->pattern),
                $this->normalizeFits($item->fit)
            );
        }

        $fallback = ProductAttributeNormalizer::normalizeText($category);
        if ($fallback === '' || mb_strlen($fallback) > 80 || !preg_match('/[\pL]/u', $fallback)) return null;
        $color = ProductAttributeNormalizer::normalizeCanonicalColor($item->color);
        return new FashionRequirement(
            $priority,
            $category,
            $fallback,
            null,
            [],
            $color === null ? [] : [$color],
            [],
            null,
            null,
            $this->normalizePatterns($item->pattern),
            $this->normalizeFits($item->fit),
            true
        );
    }

    private function normalizePatterns(?string $pattern): array
    {
        $value = ProductAttributeNormalizer::normalizeText((string) $pattern);
        return match ($value) {
            'striped', 'stripe', 'stripes', 'soc', 'ke soc' => ['striped'],
            'checked', 'check', 'plaid', 'caro', 'ke caro' => ['checked'],
            'solid', 'plain', 'tron' => ['solid'],
            default => [],
        };
    }

    private function normalizeFits(?string $fit): array
    {
        $value = ProductAttributeNormalizer::normalizeText((string) $fit);
        return match ($value) {
            'slim', 'slim fit', 'slimfit', 'om' => ['slim'],
            'relaxed', 'relaxed fit', 'loose', 'oversized', 'rong' => ['relaxed'],
            'regular', 'regular fit' => ['regular'],
            default => [],
        };
    }
}
