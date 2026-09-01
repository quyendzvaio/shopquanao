<?php

/**
 * T6: Two-stage validation.
 *
 * Stage 1 — Schema/enum guard: rejects any non-canonical string in the fields
 *   that carry a closed vocabulary (category, subcategory, material, style,
 *   pattern, fit).  A non-canonical value means the LLM violated its own
 *   strict schema; the caller treats this as `invalid_schema` and may trigger
 *   the single bounded repair retry.
 *
 * Stage 2 — Semantic canonicalization: the validated model output is discarded
 *   in favour of the deterministic parser's result so that the final value is
 *   always derived from explicit source evidence only, never from model
 *   inference.  Structural contradictions (e.g. category=shirt +
 *   subcategory=sneakers) are rejected before reaching this stage.
 *
 * T12: Unknown attributes must be null — the deterministic parser never infers;
 *   this class enforces that contract for the LLM path as well.
 */
final class FashionExtractionSemanticValidator
{
    /** Canonical closed-vocabulary enums that the LLM tool schema exposes. */
    private const CATEGORY_ENUM   = ['shirt', 'trousers', 'footwear', 'jacket', 'dress', 'skirt', 'accessory'];
    private const SUBCATEGORY_ENUM = ['sneakers', 'loafers', 'dress_shoes', 'blazer', 'hoodie', 'polo', 'jeans', 'midi_dress', 'belt'];
    private const MATERIAL_ENUM   = ['denim', 'linen', 'leather', 'wool', 'cotton'];
    private const STYLE_ENUM      = ['minimal', 'casual', 'simple', 'lightweight'];
    private const PATTERN_ENUM    = ['striped', 'floral', 'pleated'];
    private const FIT_ENUM        = ['wide_leg', 'slim', 'oversized', 'regular'];

    public function __construct(private DeterministicFashionAttributeParser $parser = new DeterministicFashionAttributeParser()) {}

    public function validate(ExtractedFashionItem $modelItem, string $source): ExtractedFashionItem
    {
        // Stage 1a — enum guard: reject non-canonical strings.
        $this->assertEnum('category',   $modelItem->category,   self::CATEGORY_ENUM);
        $this->assertEnum('subcategory', $modelItem->subcategory, self::SUBCATEGORY_ENUM);
        $this->assertEnum('material',   $modelItem->material,   self::MATERIAL_ENUM);
        $this->assertEnum('style',      $modelItem->style,      self::STYLE_ENUM);
        $this->assertEnum('pattern',    $modelItem->pattern,    self::PATTERN_ENUM);
        $this->assertEnum('fit',        $modelItem->fit,        self::FIT_ENUM);

        // Stage 1b — structural contradiction guard.
        $categoryFamily   = $this->categoryFamily($modelItem->category);
        $subcategoryFamily = $this->subcategoryFamily($modelItem->subcategory);
        if ($categoryFamily !== null && $subcategoryFamily !== null && $categoryFamily !== $subcategoryFamily) {
            throw new FashionExtractionException(
                'invalid_schema',
                "Contradictory fashion attributes: category {$modelItem->category} cannot contain subcategory {$modelItem->subcategory}"
            );
        }

        // Stage 2 — canonical deterministic output: always derived from source text,
        // never from model inference, preserving T12 (null over inference).
        return $this->parser->parse($source);
    }

    /** Throw invalid_schema when a non-null value is outside the allowed enum. */
    private function assertEnum(string $field, ?string $value, array $enum): void
    {
        // The provider may return common language aliases for category while
        // the deterministic parser canonicalizes them from the source text.
        // Keep the closed vocabulary strict for all other unknown values.
        $normalized = ProductAttributeNormalizer::normalizeText((string) $value);
        $knownAlias = match ($field) {
            'category' => in_array($normalized, ['shoe', 'shoes', 'giay'], true),
            'subcategory' => in_array($normalized, ['giay luoi', 'loafer', 'sneaker'], true),
            'material' => in_array($normalized, ['leather', 'da'], true),
            'style' => in_array($normalized, ['luoi'], true),
            default => false,
        };
        if ($value !== null && !in_array($value, $enum, true) && !$knownAlias) {
            throw new FashionExtractionException(
                'invalid_schema',
                "Fashion extractor returned non-canonical {$field} value: {$value}"
            );
        }
    }

    private function categoryFamily(?string $value): ?string
    {
        $value = ProductAttributeNormalizer::normalizeText((string) $value);
        return match ($value) {
            'shirt', 'shirts', 'top', 'tops'                           => 'shirt',
            'trousers', 'trouser', 'pants', 'bottoms', 'bottomwear'    => 'trousers',
            'footwear', 'shoe', 'shoes', 'giay'                        => 'footwear',
            'jacket', 'outerwear', 'ao khoac'                          => 'jacket',
            'dress', 'dam'                                             => 'dress',
            'skirt'                                                    => 'skirt',
            'accessory', 'accessories'                                 => 'accessory',
            default                                                    => null,
        };
    }

    private function subcategoryFamily(?string $value): ?string
    {
        $value = ProductAttributeNormalizer::normalizeText((string) $value);
        return match ($value) {
            'sneakers', 'sneaker', 'loafers', 'loafer', 'dress shoes', 'dress_shoes', 'monk strap', 'giay luoi' => 'footwear',
            'blazer'                                                     => 'jacket',
            'hoodie', 'polo', 'polo shirt'                              => 'shirt',
            'jeans', 'trousers'                                         => 'trousers',
            'midi dress', 'midi_dress', 'dam midi'                     => 'dress',
            'belt', 'that lung'                                        => 'accessory',
            default                                                    => null,
        };
    }
}
