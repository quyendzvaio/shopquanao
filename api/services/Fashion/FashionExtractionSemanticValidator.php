<?php

/** Rejects structural contradictions and keeps only source-supported values. */
final class FashionExtractionSemanticValidator
{
    public function __construct(private DeterministicFashionAttributeParser $parser = new DeterministicFashionAttributeParser()) {}

    public function validate(ExtractedFashionItem $modelItem, string $source): ExtractedFashionItem
    {
        $categoryFamily = $this->categoryFamily($modelItem->category);
        $subcategoryFamily = $this->subcategoryFamily($modelItem->subcategory);
        if ($categoryFamily !== null && $subcategoryFamily !== null && $categoryFamily !== $subcategoryFamily) {
            throw new FashionExtractionException(
                'invalid_schema',
                "Contradictory fashion attributes: category {$modelItem->category} cannot contain subcategory {$modelItem->subcategory}"
            );
        }

        // Canonical values are derived only from explicit source evidence. This
        // removes model-added material/style/color while retaining safe aliases.
        return $this->parser->parse($source);
    }

    private function categoryFamily(?string $value): ?string
    {
        $value = ProductAttributeNormalizer::normalizeText((string) $value);
        return match ($value) {
            'shirt', 'shirts', 'top', 'tops' => 'shirt',
            'trousers', 'trouser', 'pants', 'bottoms', 'bottomwear' => 'trousers',
            'footwear', 'shoe', 'shoes', 'giay' => 'footwear',
            'jacket', 'outerwear', 'ao khoac' => 'jacket',
            'dress', 'dam' => 'dress',
            'skirt' => 'skirt',
            'accessory', 'accessories' => 'accessory',
            default => null,
        };
    }

    private function subcategoryFamily(?string $value): ?string
    {
        $value = ProductAttributeNormalizer::normalizeText((string) $value);
        return match ($value) {
            'sneakers', 'sneaker', 'loafers', 'loafer', 'dress shoes', 'monk strap', 'giay luoi' => 'footwear',
            'blazer' => 'jacket',
            'hoodie', 'polo', 'polo shirt' => 'shirt',
            'jeans', 'trousers' => 'trousers',
            'midi dress', 'midi_dress', 'dam midi' => 'dress',
            'belt', 'that lung' => 'accessory',
            default => null,
        };
    }
}
