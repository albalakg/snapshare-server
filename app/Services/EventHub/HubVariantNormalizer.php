<?php

namespace App\Services\EventHub;

/**
 * Gen-2 event page templates: normalizes blocks[].variant (hero / gallery / schedule layout)
 * and EVENT_TIMELINE items[].icon before validation, so an unknown value never causes a 422.
 *
 * - variant: kept only when it is a string in config('event_landing.variants')[$type];
 *   anything else (unknown id, non-string, a block type without variants) becomes null.
 *   null / missing = "follow the template preset".
 * - items[].icon: kept only when it is in config('event_landing.timeline_icons'); else null.
 *
 * Blocks without a `variant` key are left untouched (old hubs stay byte-identical).
 */
class HubVariantNormalizer
{
    /** @var array<string, array<int, string>> */
    private array $variants;

    /** @var array<int, string> */
    private array $icons;

    public function __construct(?array $variants = null, ?array $icons = null)
    {
        $this->variants = $variants ?? (array) config('event_landing.variants', []);
        $this->icons = $icons ?? (array) config('event_landing.timeline_icons', []);
    }

    public function normalize(array $hub_config): array
    {
        if (!isset($hub_config['blocks']) || !is_array($hub_config['blocks'])) {
            return $hub_config;
        }

        foreach ($hub_config['blocks'] as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : '';

            if (array_key_exists('variant', $block)) {
                $block['variant'] = $this->normalizeVariant($type, $block['variant']);
            }

            if ($type === 'EVENT_TIMELINE' && isset($block['data']['items']) && is_array($block['data']['items'])) {
                foreach ($block['data']['items'] as $i => $item) {
                    if (is_array($item) && array_key_exists('icon', $item)) {
                        $block['data']['items'][$i]['icon'] = $this->normalizeIcon($item['icon']);
                    }
                }
            }

            $hub_config['blocks'][$index] = $block;
        }

        return $hub_config;
    }

    public function normalizeVariant(string $type, mixed $variant): ?string
    {
        if (!is_string($variant) || $variant === '') {
            return null;
        }

        $allowed = $this->variants[$type] ?? [];

        return in_array($variant, $allowed, true) ? $variant : null;
    }

    public function normalizeIcon(mixed $icon): ?string
    {
        return is_string($icon) && in_array($icon, $this->icons, true) ? $icon : null;
    }
}
