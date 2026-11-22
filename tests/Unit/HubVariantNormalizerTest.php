<?php

namespace Tests\Unit;

use App\Services\EventHub\HubVariantNormalizer;
use PHPUnit\Framework\TestCase;

class HubVariantNormalizerTest extends TestCase
{
    private function normalizer(): HubVariantNormalizer
    {
        return new HubVariantNormalizer(
            [
                'HERO_COUNTDOWN' => ['full', 'split', 'arch'],
                'GALLERY'        => ['honey', 'bento', 'polaroid'],
                'EVENT_TIMELINE' => ['timeline', 'cards', 'list'],
            ],
            ['glass', 'ring', 'dish', 'music']
        );
    }

    private function hub(array $blocks): array
    {
        return ['template_id' => 'classic', 'theme' => ['palette_id' => 'default'], 'blocks' => $blocks];
    }

    public function test_known_variant_is_kept(): void
    {
        $out = $this->normalizer()->normalize($this->hub([
            ['id' => 'hero', 'type' => 'HERO_COUNTDOWN', 'variant' => 'split', 'data' => []],
            ['id' => 'gallery', 'type' => 'GALLERY', 'variant' => 'polaroid', 'data' => []],
        ]));

        $this->assertSame('split', $out['blocks'][0]['variant']);
        $this->assertSame('polaroid', $out['blocks'][1]['variant']);
    }

    public function test_unknown_or_non_string_variant_becomes_null(): void
    {
        $out = $this->normalizer()->normalize($this->hub([
            ['id' => 'hero', 'type' => 'HERO_COUNTDOWN', 'variant' => 'zzz', 'data' => []],
            ['id' => 'gallery', 'type' => 'GALLERY', 'variant' => 5, 'data' => []],
            ['id' => 'schedule', 'type' => 'EVENT_TIMELINE', 'variant' => ['cards'], 'data' => []],
            ['id' => 'g2', 'type' => 'GALLERY', 'variant' => 'split', 'data' => []],
        ]));

        foreach ($out['blocks'] as $block) {
            $this->assertNull($block['variant']);
        }
    }

    public function test_variant_on_block_without_variants_becomes_null(): void
    {
        $out = $this->normalizer()->normalize($this->hub([
            ['id' => 'story', 'type' => 'STORY', 'variant' => 'full', 'data' => []],
        ]));

        $this->assertNull($out['blocks'][0]['variant']);
    }

    public function test_hub_without_variant_keys_is_unchanged(): void
    {
        $hub = $this->hub([
            ['id' => 'hero', 'type' => 'HERO_COUNTDOWN', 'enabled' => true, 'order' => 1, 'data' => ['title' => 'x']],
            ['id' => 'gallery', 'type' => 'GALLERY', 'enabled' => true, 'order' => 2, 'data' => ['style' => 'carousel']],
        ]);

        $this->assertSame($hub, $this->normalizer()->normalize($hub));
    }

    public function test_timeline_icons_are_normalized(): void
    {
        $out = $this->normalizer()->normalize($this->hub([
            ['id' => 'schedule', 'type' => 'EVENT_TIMELINE', 'variant' => null, 'data' => ['items' => [
                ['time' => '19:00', 'label' => 'a', 'icon' => 'glass'],
                ['time' => '20:00', 'label' => 'b', 'icon' => 'rocket'],
                ['time' => '21:00', 'label' => 'c'],
            ]]],
        ]));

        $items = $out['blocks'][0]['data']['items'];
        $this->assertSame('glass', $items[0]['icon']);
        $this->assertNull($items[1]['icon']);
        $this->assertArrayNotHasKey('icon', $items[2]);
        $this->assertNull($out['blocks'][0]['variant']);
    }
}
