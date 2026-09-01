<?php

require_once __DIR__ . '/../../controllers/chatbot/ProductAttributeNormalizer.php';
require_once __DIR__ . '/../Catalog/CatalogTaxonomy.php';

final class FashionTaxonomyNormalizer
{
    private const CATEGORY_ALIASES = [
        'áo sơ mi' => ['shirt', 'shirts', 'tops', 'top', 'topwear', 'button down', 'button-down', 'dress shirt', 'sơ mi', 'áo sơ mi', 'aosomi', 'áo sơmi', 'aoso mi', 'ao somi'],
        'áo thun' => ['t shirt', 't-shirt', 'tee', 'tees', 'áo thun', 'áo phông', 'aothun', 'aophong'],
        'áo polo' => ['polo', 'polo shirt', 'áo polo', 'aopolo'],
        'áo len' => ['sweater', 'jumper', 'knitwear', 'áo len', 'aolen'],
        'áo hoodie' => ['hoodie', 'sweatshirt', 'áo hoodie', 'aohoodie'],
        'áo khoác' => ['jacket', 'jackets', 'outerwear', 'sports jacket', 'sport jacket', 'áo khoác', 'aokhoac'],
        'áo vest' => ['blazer', 'blazers', 'suit jacket', 'áo vest', 'áo blazer', 'aovest', 'aoblazer'],
        'quần tây' => ['trousers', 'pants', 'bottoms', 'bottom', 'bottomwear', 'tailored trousers', 'dress pants', 'formal pants', 'quần tây', 'quantay'],
        'quần jeans' => ['jeans', 'jean', 'denim pants', 'quần jeans', 'quần jean', 'quanjean', 'quanjeans'],
        'quần kaki' => ['chinos', 'chino', 'khaki pants', 'quần kaki', 'quankaki'],
        'quần short' => ['shorts', 'short', 'quần short', 'quanshort', 'quanshorts'],
        'quần jogger' => ['joggers', 'jogger', 'sweatpants', 'quần jogger', 'quanjogger'],
        'chân váy' => ['skirt', 'skirts', 'chân váy', 'chanvay'],
        'váy đầm' => ['dress', 'dresses', 'gown', 'váy', 'đầm', 'váy đầm', 'vaydam'],
        'túi xách' => ['bag', 'bags', 'handbag', 'crossbody bag', 'túi xách', 'tuixach'],
        'thắt lưng' => ['belt', 'belts', 'thắt lưng', 'thatlung'],
        'kính mát' => ['sunglasses', 'sunglass', 'kính mát', 'kinhmat'],
        'đồng hồ' => ['watch', 'watches', 'đồng hồ', 'dongho'],
        'mũ' => ['hat', 'cap', 'hats', 'caps', 'mũ'],
        'giày' => ['shoes', 'shoe', 'footwear', 'sneakers', 'sneaker', 'oxford shoes', 'dress shoes', 'formal shoes', 'giày tây', 'giày'],
    ];

    private const CATEGORY_IDS = [
        'áo sơ mi' => 1, 'áo thun' => 1, 'áo polo' => 1, 'áo len' => 1,
        'áo hoodie' => 1, 'áo khoác' => 1, 'áo vest' => 1,
        'quần tây' => 2, 'quần jeans' => 2, 'quần kaki' => 2,
        'quần short' => 2, 'quần jogger' => 2,
        'chân váy' => 3, 'váy đầm' => 3,
        'túi xách' => 4, 'thắt lưng' => 4, 'kính mát' => 4, 'đồng hồ' => 4, 'mũ' => 4,
        // The current shop taxonomy has no footwear category. Keep `giày` as
        // a text search without forcing it into the accessories category.
        'giày' => null,
    ];

    private const STYLE_ALIASES = [
        'basic' => ['basic', 'minimal', 'minimalist', 'clean'],
        'trẻ trung' => ['casual', 'youthful', 'everyday', 'relaxed', 'trẻ trung'],
        'công sở' => ['tailored', 'formal', 'smart', 'smart casual', 'business', 'office', 'dressy', 'công sở'],
        'thể thao' => ['sport', 'sportswear', 'athletic', 'active', 'sneaker', 'thể thao'],
        'vintage' => ['vintage', 'retro'],
        'form rộng' => ['oversized', 'oversize', 'loose', 'relaxed fit', 'form rộng'],
        'ôm' => ['slim', 'slim fit', 'fitted', 'tailored fit', 'ôm'],
    ];

    private const MATERIAL_ALIASES = [
        'cotton' => ['cotton'], 'linen' => ['linen'], 'len' => ['wool', 'knit', 'len'],
        'kaki' => ['khaki', 'chino', 'kaki'], 'jean' => ['denim', 'jean', 'jeans'],
        'voan' => ['chiffon', 'voan'], 'lụa' => ['silk', 'satin', 'lụa'], 'da' => ['leather', 'da'],
    ];

    public function normalize(ComplementaryItemRequirement $requirement): ?ShopComplementaryRequirement
    {
        $footwearSubcategory = CatalogTaxonomy::normalizeFootwearSubcategory(
            $requirement->subcategory ?? $requirement->category
        );
        if ($footwearSubcategory !== null) {
            return new ShopComplementaryRequirement(
                $requirement->priority,
                $requirement->category,
                (string)CatalogTaxonomy::footwearSearchTerm($footwearSubcategory),
                CatalogTaxonomy::FOOTWEAR_CATEGORY_ID,
                $this->aliases($requirement->styles, self::STYLE_ALIASES),
                $this->normalizeColors($requirement->colors),
                $this->aliases($requirement->materials, self::MATERIAL_ALIASES),
                'footwear',
                $footwearSubcategory
            );
        }

        $category = $this->alias($requirement->category, self::CATEGORY_ALIASES);
        if ($category === null) {
            return null;
        }

        return new ShopComplementaryRequirement(
            $requirement->priority,
            $requirement->category,
            $category,
            self::CATEGORY_IDS[$category],
            $this->aliases($requirement->styles, self::STYLE_ALIASES),
            $this->normalizeColors($requirement->colors),
            $this->aliases($requirement->materials, self::MATERIAL_ALIASES)
        );
    }

    /** @return list<ShopComplementaryRequirement> */
    public function normalizePlan(ComplementaryPlan $plan): array
    {
        $normalized = [];
        foreach ($plan->requirements as $requirement) {
            $item = $this->normalize($requirement);
            if ($item !== null) {
                $normalized[] = $item;
            }
        }
        return $normalized;
    }

    private function aliases(array $values, array $dictionary): array
    {
        $result = [];
        foreach ($values as $value) {
            $alias = $this->alias($value, $dictionary);
            if ($alias !== null) {
                $result[] = $alias;
            }
        }
        return array_values(array_unique($result));
    }

    private function normalizeColors(array $values): array
    {
        $colors = [];
        foreach ($values as $color) {
            $normalized = ProductAttributeNormalizer::normalizeCanonicalColor($color);
            if ($normalized !== null) $colors[] = $normalized;
        }
        return array_values(array_unique($colors));
    }

    private function alias(string $value, array $dictionary): ?string
    {
        $needle = ProductAttributeNormalizer::normalizeText($value);
        foreach ($dictionary as $canonical => $aliases) {
            foreach (array_merge([$canonical], $aliases) as $alias) {
                if ($needle === ProductAttributeNormalizer::normalizeText($alias)) {
                    return $canonical;
                }
            }
        }
        return null;
    }
}
