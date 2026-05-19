<?php

namespace Tests\Unit;

use App\Support\ProductPayloadNormalizer;
use PHPUnit\Framework\TestCase;

class ProductPayloadNormalizerTest extends TestCase
{
    public function test_sets_color_and_hex_when_missing(): void
    {
        $out = ProductPayloadNormalizer::applyRepresentationAndCostDefaults([
            'name' => 'Widget',
            'price' => 10,
        ]);

        $this->assertSame('color', $out['representation_type']);
        $this->assertSame(ProductPayloadNormalizer::DEFAULT_COLOR_HEX, $out['representation']);
        $this->assertNull($out['cost']);
    }

    public function test_preserves_explicit_cost_zero(): void
    {
        $out = ProductPayloadNormalizer::applyRepresentationAndCostDefaults([
            'representation_type' => 'color',
            'representation' => 'ff0000',
            'cost' => 0,
        ]);

        $this->assertSame(0, $out['cost']);
        $this->assertSame('ff0000', $out['representation']);
    }

    public function test_keeps_nonempty_representation_type(): void
    {
        $out = ProductPayloadNormalizer::applyRepresentationAndCostDefaults([
            'representation_type' => 'image',
            'representation' => 'https://example.com/i.png',
        ]);

        $this->assertSame('image', $out['representation_type']);
        $this->assertSame('https://example.com/i.png', $out['representation']);
    }
}
