<?php

require_once __DIR__ . '/../../controllers/chatbot/ProductAttributeNormalizer.php';

/** Canonical category/subcategory normalization at the catalog seam. */
final class CatalogTaxonomy
{
    public const FOOTWEAR_CATEGORY_ID = 5;

    private const FOOTWEAR_ALIASES = [
        'dress_shoes' => [
            'dress shoes', 'dress shoe', 'formal shoes', 'formal shoe', 'oxford shoes',
            'oxford shoe', 'oxfords', 'oxford', 'derby shoes', 'derby shoe', 'derbies',
            'derby', 'giày tây', 'giay tay',
            'monk strap shoes', 'monk strap shoe', 'monk straps',
        ],
        'sneakers' => [
            'sneakers', 'sneaker', 'trainers', 'trainer', 'athletic shoes', 'running shoes',
            'giày sneaker', 'giay sneaker', 'giày thể thao', 'giay the thao',
        ],
        'loafers' => ['loafers', 'loafer', 'giày lười', 'giay luoi'],
        'boots' => ['boots', 'boot', 'bốt', 'bot'],
        'sandals' => ['sandals', 'sandal', 'dép sandal', 'dep sandal'],
        'other' => ['footwear', 'shoes', 'shoe', 'giày dép', 'giay dep', 'giày', 'giay'],
    ];

    private const FOOTWEAR_SEARCH_TERMS = [
        'sneakers' => 'giày sneaker',
        'dress_shoes' => 'giày tây',
        'loafers' => 'giày loafer',
        'boots' => 'bốt',
        'sandals' => 'sandal',
        'other' => 'giày',
    ];

    public static function normalizeFootwearSubcategory(?string $value): ?string
    {
        $text = ProductAttributeNormalizer::normalizeText((string)$value);
        if ($text === '') return null;

        $candidates = [];
        foreach (self::FOOTWEAR_ALIASES as $subcategory => $aliases) {
            foreach ($aliases as $alias) {
                $normalized = ProductAttributeNormalizer::normalizeText($alias);
                $candidates[] = [$subcategory, $normalized];
            }
        }
        usort($candidates, fn($a, $b) => mb_strlen($b[1]) <=> mb_strlen($a[1]));
        foreach ($candidates as [$subcategory, $alias]) {
            if ($text === $alias || preg_match('/(^|\s)' . preg_quote($alias, '/') . '(\s|$)/u', $text)) {
                return $subcategory;
            }
        }
        return null;
    }

    public static function normalizeSearchArguments(array $arguments): array
    {
        $category = ProductAttributeNormalizer::normalizeText((string)($arguments['category'] ?? ''));
        $subcategoryInput = (string)($arguments['subcategory'] ?? '');
        $search = (string)($arguments['search'] ?? '');
        $subcategory = self::normalizeFootwearSubcategory($subcategoryInput)
            ?? self::normalizeFootwearSubcategory($search);

        if ($category === 'footwear' || in_array($category, ['shoes', 'shoe', 'giay', 'giay dep'], true) || $subcategory !== null) {
            $subcategory ??= 'other';
            $arguments['category'] = 'footwear';
            $arguments['category_id'] = self::FOOTWEAR_CATEGORY_ID;
            $arguments['subcategory'] = $subcategory;
            $arguments['search'] = self::FOOTWEAR_SEARCH_TERMS[$subcategory];
        }
        return $arguments;
    }

    public static function footwearSearchTerm(string $subcategory): ?string
    {
        return self::FOOTWEAR_SEARCH_TERMS[$subcategory] ?? null;
    }
}
