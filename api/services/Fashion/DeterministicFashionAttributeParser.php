<?php

/**
 * Small explicit-attribute parser used as a safe fast path and as the source
 * evidence for semantic validation. It intentionally covers only the V1
 * taxonomy and never infers an attribute that is absent from the text.
 */
final class DeterministicFashionAttributeParser
{
    public function parse(string $source): ExtractedFashionItem
    {
        $text = ProductAttributeNormalizer::normalizeText($source);
        $subcategory = $this->first($text, [
            'dress_shoes' => ['monk strap shoes', 'monk strap'],
            'midi_dress' => ['midi dress', 'vay midi', 'dam midi'],
            'sneakers' => ['sneakers', 'sneaker', 'giay sneaker', 'giay the thao'],
            'loafers' => ['loafers', 'loafer', 'giay luoi'],
            'blazer' => ['blazer'],
            'hoodie' => ['hoodie'],
            'belt' => ['belt', 'that lung'],
            'polo' => ['polo'],
            // The corpus intentionally treats Vietnamese "quan jean" as a
            // material cue only, while explicit English jeans is a subtype.
            'jeans' => ['jeans'],
        ]);

        $category = match (true) {
            in_array($subcategory, ['sneakers', 'loafers', 'dress_shoes'], true)
                || $this->hasAny($text, ['shoes', 'shoe', 'giay']) => 'footwear',
            $subcategory === 'belt' => 'accessory',
            $subcategory === 'midi_dress' || $this->hasAny($text, ['dress', 'dam midi', 'vay midi']) => 'dress',
            $this->hasAny($text, ['skirt', 'chan vay']) => 'skirt',
            $subcategory === 'blazer' || $this->hasAny($text, ['jacket', 'ao khoac']) => 'jacket',
            in_array($subcategory, ['hoodie', 'polo'], true) || $this->hasAny($text, ['shirt', 'ao so mi']) => 'shirt',
            $subcategory === 'jeans' || $this->hasAny($text, ['trousers', 'trouser', 'pants', 'quan']) => 'trousers',
            default => null,
        };

        $color = ProductAttributeNormalizer::normalizeCanonicalColor($source);
        $material = $this->first($text, [
            'denim' => ['denim', 'jeans', 'jean'],
            'linen' => ['linen', 'vai lanh', 'lanh'],
            'leather' => ['leather', 'da'],
            'wool' => ['wool', 'len'],
            'cotton' => ['cotton'],
        ]);
        $style = $this->first($text, [
            'minimal' => ['minimal'],
            'casual' => ['casual'],
            'simple' => ['simple'],
            'lightweight' => ['lightweight', 'ao khoac nhe'],
        ]);
        $pattern = $this->first($text, [
            'striped' => ['striped', 'stripe'],
            'floral' => ['floral', 'hoa tiet hoa'],
            'pleated' => ['pleated', 'xep ly'],
        ]);
        $fit = $this->first($text, [
            'wide_leg' => ['wide leg', 'ong rong'],
            'slim' => ['slim fit', 'slim'],
            'oversized' => ['oversized', 'dang rong'],
            'regular' => ['regular fit', 'dang thuong'],
        ]);

        return new ExtractedFashionItem($category, $subcategory, $color, $material, $style, $pattern, $fit);
    }

    public function isConfidentFastPath(ExtractedFashionItem $item): bool
    {
        if ($item->category === null) return false;
        return count(array_filter($item->toArray(), static fn (?string $value): bool => $value !== null)) >= 3;
    }

    /** @param array<string,list<string>> $aliases */
    private function first(string $text, array $aliases): ?string
    {
        foreach ($aliases as $canonical => $terms) {
            if ($this->hasAny($text, $terms)) return $canonical;
        }
        return null;
    }

    /** @param list<string> $terms */
    private function hasAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $needle = ProductAttributeNormalizer::normalizeText($term);
            if ($needle !== '' && preg_match('/(^|\s)' . preg_quote($needle, '/') . '(\s|$)/u', $text) === 1) return true;
        }
        return false;
    }
}
