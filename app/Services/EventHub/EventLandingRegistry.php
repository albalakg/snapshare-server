<?php

namespace App\Services\EventHub;

class EventLandingRegistry
{
    private const LEGACY_BLOCK_TYPES = [
        'HERO_COUNTDOWN',
        'SNAPSHARE_CTA',
        'EVENT_TIMELINE',
        'SMART_NAVIGATION',
        'WISHING_WELL',
        'LINK_LIST',
        'RICH_TEXT',
        'MEDIA_STRIP',
    ];

    private static ?array $stock_keys = null;

    public static function templateIds(): array
    {
        return array_keys(config('event_landing.templates', []));
    }

    public static function template(string $id): ?array
    {
        $template = config("event_landing.templates.{$id}");

        return is_array($template) ? $template : null;
    }

    public static function allowedFonts(): array
    {
        return config('event_landing.allowed_fonts', []);
    }

    public static function isValidStockKey(string $key): bool
    {
        return isset(self::stockKeys()[$key]);
    }

    public static function isLegacyBlockType(string $type): bool
    {
        return in_array($type, self::LEGACY_BLOCK_TYPES, true);
    }

    /**
     * @return array<string, true>
     */
    private static function stockKeys(): array
    {
        if (self::$stock_keys !== null) {
            return self::$stock_keys;
        }

        $keys = [];

        foreach (config('event_landing.templates', []) as $template) {
            foreach ($template['stock'] ?? [] as $stock_key) {
                if (is_string($stock_key) && $stock_key !== '') {
                    $keys[$stock_key] = true;
                }
            }
        }

        foreach (config('event_landing.art_stock_stems', []) as $stem) {
            if (is_string($stem) && $stem !== '') {
                $keys['art/' . $stem] = true;
            }
        }

        // Explicitly exclude album-00 and any art/album-* keys from stock.
        unset($keys['art/album-00']);

        self::$stock_keys = $keys;

        return self::$stock_keys;
    }
}
