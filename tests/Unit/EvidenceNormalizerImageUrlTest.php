<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EvidenceNormalizerImageUrlTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function imageProvider(): array
    {
        return [
            'legacy images/ prefix' => ['images/x.jpg', '/assets/images/x.jpg'],
            'bare file name' => ['x.jpg', '/assets/images/x.jpg'],
            'already assets/images' => ['assets/images/x.jpg', '/assets/images/x.jpg'],
        ];
    }

    /** @dataProvider imageProvider */
    public function testImageUrlIsNormalised(string $stored, string $expected): void
    {
        $execution = ['results' => [[
            'tool' => 'get_product_detail',
            'success' => true,
            'result' => ['product' => ['id' => 5, 'name' => 'Ao', 'price' => 100, 'stock' => 3, 'image' => $stored]],
        ]]];

        $out = (new EvidenceNormalizer())->normalize([], $execution);

        $this->assertSame($expected, $out['cards'][0]['image_url']);
    }
}
