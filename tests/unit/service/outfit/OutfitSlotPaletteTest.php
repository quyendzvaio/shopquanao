<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitSlotPaletteTest extends TestCase
{
    public function testSlotsFollowCategoryAndSkirtsAreBottoms(): void
    {
        $this->assertSame(OutfitSlot::TOP, OutfitSlot::resolve(1, 'Áo Thun Cotton Basic Trắng'));
        $this->assertSame(OutfitSlot::BOTTOM, OutfitSlot::resolve(2, 'Quần Tây Ống Đứng Xám'));
        $this->assertSame(OutfitSlot::BOTTOM, OutfitSlot::resolve(3, 'Chân Váy Pleat xếp ly Midi'));
        $this->assertSame(OutfitSlot::DRESS, OutfitSlot::resolve(3, 'Váy Suông Thun Gân Đen'));
        $this->assertSame(OutfitSlot::ACCESSORY, OutfitSlot::resolve(4, 'Thắt Lưng Da Bò Nguyên Tấm'));
        $this->assertNull(OutfitSlot::resolve(5, 'Giày'));
    }

    public function testStatementPiecesAreAccentColoursOrPrints(): void
    {
        $this->assertTrue(OutfitPalette::isStatement('Áo Polo Thể Thao Cao Cấp Đỏ'));
        $this->assertTrue(OutfitPalette::isStatement('Áo Sơ Mi Caro Đỏ Đen'));
        $this->assertTrue(OutfitPalette::isStatement('Quần Kaki Ống Rộng Màu Xanh Rêu'));
        $this->assertFalse(OutfitPalette::isStatement('Áo Vest Blazer Công Sở Xanh Navy'));
        $this->assertFalse(OutfitPalette::isStatement('Quần Jeans Slimfit Xanh Đậm'));
        $this->assertFalse(OutfitPalette::isStatement('Áo Dài Tay Cổ Tim'), '"Tim" (neckline) is not "tím"');
    }
}
