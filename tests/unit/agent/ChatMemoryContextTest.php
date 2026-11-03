<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatMemoryContextTest extends TestCase
{
    private function memory(): array
    {
        return [
            'summary' => '- size: M',
            'slots' => [
                'product_type' => 'quần', 'category_id' => 2, 'color' => 'đen', 'style' => 'basic', 'occasion' => 'đi làm',
                'material' => 'jean', 'size' => 'M', 'height_cm' => 160, 'weight_kg' => null, 'budget' => 500000,
                'min_price' => null, 'max_price' => 700000, 'gender' => 'nữ',
                'freeship_nudged_fingerprint' => 'fp-1',
            ],
            'long_term' => [
                'preferences' => [
                    'favorite_brand' => 'BRAND_X', 'favorite_country' => 'COUNTRY_X', 'favorite_texture' => 'cotton',
                    'favorite_style' => 'basic', 'favorite_color' => 'đen',
                    'avoid_ingredient' => ['INGREDIENT_X'], 'avoid_material' => ['len'], 'avoid_style' => [],
                ],
                'stable_facts' => ['skin_type' => 'SKIN_X', 'skin_tone' => 'TONE_X', 'pregnant' => 'PREGNANT_X', 'body_shape' => 'SHAPE_X', 'usual_size' => 'M'],
                'important_events' => [['date' => '2026-01-01', 'event' => 'EVENT_X']],
                'feedback' => [['text' => 'FEEDBACK_X']],
                'purchase_history' => [
                    ['date' => '2026-10-07', 'product' => 'Áo 7'], ['date' => '2026-10-06', 'product' => 'Áo 6'],
                    ['date' => '2026-10-05', 'product' => 'Áo 5'], ['date' => '2026-10-04', 'product' => 'Áo 4'],
                    ['date' => '2026-10-03', 'product' => 'Áo 3'], ['date' => '2026-10-02', 'product' => 'Áo 2'],
                ],
            ],
        ];
    }

    public function testForbiddenFieldsNeverSent(): void
    {
        $json = json_encode(ChatMemoryContext::build($this->memory(), 12), JSON_UNESCAPED_UNICODE);

        foreach ([
            'BRAND_X', 'COUNTRY_X', 'INGREDIENT_X', 'SKIN_X', 'TONE_X', 'PREGNANT_X', 'SHAPE_X', 'EVENT_X', 'FEEDBACK_X',
            'pregnant', 'skin_type', 'skin_tone', 'body_shape', 'important_events', 'feedback', 'avoid_ingredient',
            'favorite_brand', 'favorite_country', 'product_type', 'category_id', '"color"', '"style"', 'occasion', '"material"',
            'freeship_nudged_fingerprint', 'fp-1',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $json, $forbidden);
        }
    }

    public function testGuestHasNoProfile(): void
    {
        $context = ChatMemoryContext::build(['summary' => 's', 'slots' => ['size' => 'L'], 'long_term' => null], null);

        $this->assertNull($context['profile']);
        $this->assertSame('s', $context['summary']);
        $this->assertSame(['size' => 'L'], $context['slots']);
    }

    public function testKeepsAllowedSlots(): void
    {
        $context = ChatMemoryContext::build($this->memory(), 12);

        $this->assertSame(
            ['size' => 'M', 'height_cm' => 160, 'budget' => 500000, 'max_price' => 700000, 'gender' => 'nữ'],
            $context['slots']
        );
        $this->assertArrayNotHasKey('product_type', $context['slots']);
        $this->assertArrayNotHasKey('weight_kg', $context['slots']);
    }

    public function testProfileIsAllowListedAndDropsEmptyValues(): void
    {
        $profile = ChatMemoryContext::build($this->memory(), 12)['profile'];

        $this->assertSame([
            'usual_size' => 'M',
            'favorite_color' => 'đen',
            'favorite_style' => 'basic',
            'favorite_texture' => 'cotton',
            'avoid_material' => ['len'],
            'recent_purchases' => ['Áo 7', 'Áo 6', 'Áo 5', 'Áo 4', 'Áo 3'],
        ], $profile);
    }

    public function testLoggedInUserWithoutLongTermGetsAnEmptyProfile(): void
    {
        $context = ChatMemoryContext::build(['summary' => '', 'slots' => [], 'long_term' => null], 12);

        $this->assertSame([], $context['profile']);
    }
}
