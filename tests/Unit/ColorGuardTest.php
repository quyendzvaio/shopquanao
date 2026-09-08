<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Color guards (SRS FR-003): "cổ tim" (sweetheart neckline) must never resolve
 * to purple, on either the query side or the product side.
 */
final class ColorGuardTest extends TestCase
{
    public function testBareTimWithoutColorPrefixIsNotPurple(): void
    {
        $this->assertNull(ProductAttributeNormalizer::normalizeColor('tim'));
    }

    public function testMauTimPrefixIsPurple(): void
    {
        $this->assertSame('tím', ProductAttributeNormalizer::normalizeColor('màu tím'));
        $this->assertSame('tím', ProductAttributeNormalizer::normalizeColor('purple'));
    }

    public function testBlackAliasesResolve(): void
    {
        $this->assertSame('đen', ProductAttributeNormalizer::normalizeColor('black'));
        $this->assertSame('đen', ProductAttributeNormalizer::normalizeColor('den'));
    }

    public function testNecklineProductDoesNotExtractPurple(): void
    {
        $colors = ProductAttributeNormalizer::extractCanonicalColorsFromProduct([
            'name' => 'Áo Dài Tay Cổ Tim',
            'description' => 'Thiết kế cổ tim tinh tế, vải thun co giãn 4 chiều.',
        ]);
        $this->assertNotContains('purple', $colors);
    }

    public function testBlackNamedProductExtractsBlack(): void
    {
        $colors = ProductAttributeNormalizer::extractCanonicalColorsFromProduct([
            'name' => 'Áo Khoác Bomber Kaki Đen',
            'description' => 'Thiết kế mạnh mẽ, giữ ấm tốt.',
        ]);
        $this->assertContains('black', $colors);
    }

    public function testBlackProductMatchesBlackConstraint(): void
    {
        $this->assertTrue(ProductAttributeNormalizer::productMatchesConstraints(
            [
                'category_id' => 1,
                'price' => 550000,
                'stock' => 12,
                'name' => 'Áo Khoác Bomber Kaki Đen',
                'description' => 'Thiết kế mạnh mẽ.',
                'sizes' => [['size_name' => 'M']],
            ],
            ['color' => 'đen', 'size' => 'M', 'max_price' => 600000, 'in_stock' => true]
        ));
    }

    public function testNecklineProductFailsPurpleConstraint(): void
    {
        $this->assertFalse(ProductAttributeNormalizer::productMatchesConstraints(
            [
                'category_id' => 1,
                'price' => 250000,
                'stock' => 10,
                'name' => 'Áo Dài Tay Cổ Tim',
                'description' => 'Thiết kế cổ tim tinh tế.',
            ],
            ['color' => 'tím']
        ));
    }
}
