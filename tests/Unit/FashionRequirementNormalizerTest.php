<?php

use PHPUnit\Framework\TestCase;

final class FashionRequirementNormalizerTest extends TestCase
{
    public function testExtractedAliasesBecomeValidatedSearchRequirements(): void
    {
        $normalizer = new FashionRequirementNormalizer(new NormalizerRecordingFashionMetrics());
        $requirements = $normalizer->normalize([
            new ExtractedFashionItem('pants', null, 'trắng', 'denim', null, null, null),
            new ExtractedFashionItem('footwear', 'trainers', 'đen', null, 'minimal', null, null),
        ]);

        self::assertCount(2, $requirements);
        self::assertSame('quần tây', $requirements[0]->search);
        self::assertSame(['white'], $requirements[0]->colors);
        self::assertSame(['jean'], $requirements[0]->materials);
        self::assertSame('footwear', $requirements[1]->canonicalCategory);
        self::assertSame('sneakers', $requirements[1]->subcategory);
        self::assertSame(['black'], $requirements[1]->colors);
    }

    public function testImpossibleCategorySubcategoryPairIsRejected(): void
    {
        $metrics = new NormalizerRecordingFashionMetrics();
        $requirements = (new FashionRequirementNormalizer($metrics))->normalize([
            new ExtractedFashionItem('shirt', 'sneakers', 'white', null, null, null, null),
        ]);

        self::assertSame([], $requirements);
        self::assertSame(1, $metrics->counts['fashion_normalization_unknown_category_total'] ?? 0);
    }

    public function testVerifiedMonkStrapRuleAndSafeUnknownFallbackDoNotMisclassify(): void
    {
        $requirements = (new FashionRequirementNormalizer(new NormalizerRecordingFashionMetrics()))->normalize([
            new ExtractedFashionItem('monk strap shoes', null, null, 'leather', null, null, null),
            new ExtractedFashionItem('capelet', null, 'beige', null, null, null, null),
        ]);

        self::assertSame('footwear', $requirements[0]->canonicalCategory);
        self::assertSame('dress_shoes', $requirements[0]->subcategory);
        self::assertSame('capelet', $requirements[1]->search);
        self::assertTrue($requirements[1]->textFallback);
        self::assertNull($requirements[1]->categoryId);
    }

    public function testAllUnknownItemProducesNoSearchRequirement(): void
    {
        self::assertSame([], (new FashionRequirementNormalizer())->normalize([
            new ExtractedFashionItem(null, null, null, null, null, null, null),
        ]));
    }
}

final class NormalizerRecordingFashionMetrics implements FashionPipelineMetrics
{
    public array $counts = [];
    public function increment(string $metric): void { $this->counts[$metric] = ($this->counts[$metric] ?? 0) + 1; }
}
