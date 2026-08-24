<?php

final class FashionTaxonomyNormalizerTest extends \PHPUnit\Framework\TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('categoryAliases')]
    public function testProviderCategoryAliasesReuseShopTaxonomy(string $providerCategory, string $shopSearch, ?int $categoryId): void
    {
        $requirement = new ComplementaryItemRequirement($providerCategory);
        $normalized = (new FashionTaxonomyNormalizer())->normalize($requirement);

        $this->assertNotNull($normalized);
        $this->assertSame($shopSearch, $normalized->search);
        $this->assertSame($categoryId, $normalized->categoryId);
    }

    public static function categoryAliases(): array
    {
        return [
            ['blazer', 'áo vest', 1],
            ['sports jacket', 'áo khoác', 1],
            ['tailored trousers', 'quần tây', 2],
            ['Oxford shoes', 'giày tây', 5],
        ];
    }

    public function testStylesColorsAndMaterialsNormalizeToExistingSearchTerms(): void
    {
        $requirement = new ComplementaryItemRequirement(
            'trousers',
            ['casual', 'tailored', 'nonsense-provider-style'],
            ['beige', 'not-a-real-color'],
            ['denim', 'unknown-fabric'],
            2
        );
        $normalized = (new FashionTaxonomyNormalizer())->normalize($requirement);

        $this->assertSame(['trẻ trung', 'công sở'], $normalized->styles);
        $this->assertSame(['beige'], $normalized->colors);
        $this->assertSame(['jean'], $normalized->materials);
        $this->assertSame([
            'search' => 'quần tây',
            'in_stock' => true,
            'category_id' => 2,
            'color' => 'beige',
            'style' => ['trẻ trung', 'công sở'],
            'material' => ['jean'],
        ], $normalized->searchArguments());
    }

    public function testMeaninglessUnknownCategoryIsRejectedGracefully(): void
    {
        $requirement = new ComplementaryItemRequirement('provider-internal-widget');
        $this->assertNull((new FashionTaxonomyNormalizer())->normalize($requirement));
    }

    public function testFootwearRequirementCarriesCanonicalCategorySubcategoryAndColor(): void
    {
        $normalized = (new FashionTaxonomyNormalizer())->normalize(
            new ComplementaryItemRequirement('white sneakers', [], ['trắng'])
        );

        $this->assertSame('footwear', $normalized->canonicalCategory);
        $this->assertSame('sneakers', $normalized->subcategory);
        $this->assertSame(['white'], $normalized->colors);
        $this->assertSame('giày sneaker', $normalized->search);
    }
}
