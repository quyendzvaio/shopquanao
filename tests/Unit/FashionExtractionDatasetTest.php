<?php

final class FashionExtractionDatasetTest extends \PHPUnit\Framework\TestCase
{
    public function testDatasetContainsAtLeastThirtyBilingualAndUnknownCasesWithExactSchema(): void
    {
        $cases = require ROOT_DIR . '/tests/fixtures/findmine/fashion-extraction-cases.php';
        self::assertGreaterThanOrEqual(30, count($cases));
        self::assertTrue((bool) array_filter($cases, static fn (array $case): bool => preg_match('/[\x{00C0}-\x{1EF9}]/u', $case['text']) === 1));
        self::assertTrue((bool) array_filter($cases, static fn (array $case): bool => $case['expected']['category'] === null));
        foreach ($cases as $case) {
            self::assertSame(['category', 'subcategory', 'color', 'material', 'style', 'pattern', 'fit'], array_keys($case['expected']));
        }
    }
}
