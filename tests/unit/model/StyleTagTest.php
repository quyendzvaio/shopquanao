<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StyleTagTest extends TestCase
{
    public function testParseListKeepsOnlyKnownTagsUniqueAndAtMostThree(): void
    {
        $this->assertSame(['congso', 'phongvan', 'basic'], StyleTag::parseList(' congso,phongvan,,xyz,congso,basic,hendo'));
        $this->assertSame([], StyleTag::parseList(null));
    }

    public function testToColumnNormalisesFormInput(): void
    {
        $this->assertSame('dichoi,dibien', StyleTag::toColumn(['dichoi', 'bogus', 'dibien']));
    }

    public function testFromTextFindsTagsInOrderOfAppearance(): void
    {
        $this->assertSame(['mualanh', 'congso'], StyleTag::fromText('Trời lạnh mặc gì đi làm, có 1.5 triệu'));
        $this->assertSame(['phongvan'], StyleTag::fromText('Có 800k, cần đồ đi phỏng vấn, cao 1m70'));
        $this->assertSame(['thethao'], StyleTag::fromText('gợi ý set đồ tập gym'));
    }

    public function testFromTextIgnoresSubstringsInsideOtherWords(): void
    {
        $this->assertSame([], StyleTag::fromText('update giỏ hàng giúp tôi'));
        $this->assertSame([], StyleTag::fromText('áo thun dưới 300k'));
    }

    public function testLabelsAreVietnamese(): void
    {
        $this->assertSame('phỏng vấn', StyleTag::label('phongvan'));
        $this->assertCount(10, StyleTag::ALL);
    }
}
