<?php

namespace App\Services\EventHub;

/**
 * Normalize hub_config.presentation and hub_config.save_the_date.
 * Unknown styles become "envelope" (no 422). Unknown presentation becomes "landing".
 */
class SaveTheDateNormalizer
{
    public const STYLES = [
        'envelope',
        'calendar',
        'minimal',
        'polaroid',
        'neon',
        'botanical',
        'scratch',
        'boarding',
    ];

    public const KINDS = [
        'wedding',
        'barmitzvah',
        'birthday',
        'brit',
        'other',
    ];

    public const SHOW_KEYS = [
        'countdown',
        'calendar',
        'whatsapp',
        'line',
        'venue',
        'hebdate',
        'footer',
    ];

    public const DEFAULT_SHOW = [
        'countdown' => true,
        'calendar'  => true,
        'whatsapp'  => true,
        'line'      => true,
        'venue'     => true,
        'hebdate'   => true,
        'footer'    => true,
    ];

    public const CONTENT_STRINGS = [
        'a', 'b', 'title', 'sub', 'date', 'time', 'venue', 'city', 'line',
    ];

    public function normalizeHubConfig(array $hub_config): array
    {
        $presentation = $hub_config['presentation'] ?? 'landing';
        $hub_config['presentation'] = $presentation === 'save_the_date' ? 'save_the_date' : 'landing';

        if (!array_key_exists('save_the_date', $hub_config)) {
            return $hub_config;
        }

        if (!is_array($hub_config['save_the_date'])) {
            unset($hub_config['save_the_date']);

            return $hub_config;
        }

        $hub_config['save_the_date'] = $this->normalizeSlice($hub_config['save_the_date']);

        return $hub_config;
    }

    public function normalizeSlice(array $slice): array
    {
        $style = is_string($slice['style'] ?? null) ? $slice['style'] : 'envelope';
        if (!in_array($style, self::STYLES, true)) {
            $style = 'envelope';
        }

        $kind = is_string($slice['content']['kind'] ?? null) ? $slice['content']['kind'] : 'other';
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'other';
        }

        $contentIn = is_array($slice['content'] ?? null) ? $slice['content'] : [];
        $content = [
            'kind'  => $kind,
            'a'     => $this->clip(is_string($contentIn['a'] ?? null) ? $contentIn['a'] : ''),
            'b'     => $this->clip(is_string($contentIn['b'] ?? null) ? $contentIn['b'] : ''),
            'title' => $this->clip(is_string($contentIn['title'] ?? null) ? $contentIn['title'] : '', 200),
            'sub'   => $this->clip(is_string($contentIn['sub'] ?? null) ? $contentIn['sub'] : ''),
            'date'  => $this->clip(is_string($contentIn['date'] ?? null) ? $contentIn['date'] : '', 32),
            'time'  => $this->clip(is_string($contentIn['time'] ?? null) ? $contentIn['time'] : '', 16),
            'venue' => $this->clip(is_string($contentIn['venue'] ?? null) ? $contentIn['venue'] : ''),
            'city'  => $this->clip(is_string($contentIn['city'] ?? null) ? $contentIn['city'] : ''),
            'line'  => $this->clip(is_string($contentIn['line'] ?? null) ? $contentIn['line'] : '', 200),
            'photo' => $this->normalizePhoto($contentIn['photo'] ?? null),
        ];

        $optsIn = is_array($slice['style_opts'] ?? null) ? $slice['style_opts'] : [];
        $style_opts = [];
        foreach (self::STYLES as $id) {
            $style_opts[$id] = $this->normalizeStyleOpts($id, is_array($optsIn[$id] ?? null) ? $optsIn[$id] : []);
        }

        $showIn = is_array($slice['show'] ?? null) ? $slice['show'] : [];
        $show = [];
        foreach (self::SHOW_KEYS as $key) {
            $show[$key] = array_key_exists($key, $showIn)
                ? (bool) $showIn[$key]
                : self::DEFAULT_SHOW[$key];
        }

        return [
            'style'      => $style,
            'style_opts' => $style_opts,
            'content'    => $content,
            'show'       => $show,
        ];
    }

    private function normalizeStyleOpts(string $style, array $opts): array
    {
        $out = [];
        if (isset($opts['accent']) && is_string($opts['accent'])) {
            $out['accent'] = $this->clip($opts['accent'], 40);
        }
        foreach ($opts as $key => $value) {
            if ($key === 'accent' || !is_string($key)) {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key)) {
                continue;
            }
            if (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
                $out[$key] = (bool) $value;
            }
        }

        return $out;
    }

    private function normalizePhoto(mixed $photo): mixed
    {
        if ($photo === null || $photo === '') {
            return null;
        }
        if (is_string($photo)) {
            $trimmed = $this->clip($photo, 500);

            return $trimmed === '' ? null : $trimmed;
        }
        if (!is_array($photo)) {
            return null;
        }

        if (array_key_exists('stock', $photo) && is_string($photo['stock']) && $photo['stock'] !== '') {
            return ['stock' => $this->clip($photo['stock'], 200)];
        }
        if (array_key_exists('asset_id', $photo) && is_numeric($photo['asset_id'])) {
            return ['asset_id' => (int) $photo['asset_id']];
        }
        if (array_key_exists('url', $photo) && is_string($photo['url']) && $photo['url'] !== '') {
            return ['url' => $this->clip($photo['url'], 500)];
        }

        return null;
    }

    private function clip(string $value, int $max = 200): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
