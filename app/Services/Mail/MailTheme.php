<?php

namespace App\Services\Mail;

/**
 * Colour tokens + role map for the v2 email layout (approved mock t141u).
 * Tokens mirror web helpers/snapsharePalette.ts (SNAPSHARE.*). Inline styles carry the LIGHT value;
 * dark mode overrides the role classes with !important inside @media (prefers-color-scheme: dark)
 * (+ [data-ogsc]/[data-ogsb] for Outlook.com). Hero/header are dark in both schemes, the CTA is gold
 * with espresso text in both schemes.
 */
class MailTheme
{
    public const FONT = "Heebo,'Segoe UI',Arial,Tahoma,sans-serif";

    public const TOKENS = [
        'espresso' => '#12100e', 'ivory' => '#f3eee6', 'paper' => '#fffcfa', 'ink' => '#161310', 'muted' => '#5e564e',
        'gold' => '#c9a36a', 'goldInk' => '#6e5228', 'roseInk' => '#8a3540', 'deep2' => '#1c1814',
    ];

    /** role => [light, dark] */
    public const BG = [
        'bg-page' => ['#f3eee6', '#0c0a09'],
        'bg-card' => ['#fffcfa', '#1c1814'],
        'bg-hero' => ['#12100e', '#12100e'],
        'bg-soft' => ['#f3eee6', '#26201b'],
        'bg-warn' => ['#f8e9ea', '#2c1a1d'],
        'bg-ok'   => ['#e4f3ea', '#16261d'],
        'bg-chip' => ['#e8e0d4', '#322a23'],
    ];

    public const TX = [
        't-ink'   => ['#161310', '#f3eee6'],
        't-muted' => ['#5e564e', '#c9bfb2'],
        't-gold'  => ['#6e5228', '#c9a36a'],
        't-warn'  => ['#8a3540', '#f0a0a8'],
        't-ok'    => ['#1d6b3d', '#7fdca2'],
        't-foot'  => ['#5e564e', '#b8ada0'],
        't-link'  => ['#6e5228', '#e0c28f'],
    ];

    public const BD = [
        'bd-soft' => ['#e3dacb', '#3a322a'],
        'bd-warn' => ['#8a3540', '#f0a0a8'],
        'bd-ok'   => ['#1d6b3d', '#7fdca2'],
        'bd-gold' => ['#c9a36a', '#c9a36a'],
    ];

    // Fixed (same in both schemes): text on the dark hero/header, CTA.
    public const HERO_TX = '#f3eee6';
    public const HERO_MUTED = '#d6ccbe';
    public const HERO_GOLD = '#c9a36a';
    public const HERO_WARN = '#ef8e97';
    public const BTN_BG = '#c9a36a';
    public const BTN_TX = '#12100e';

    public static function bg(string $role): string
    {
        return self::BG[$role][0];
    }

    public static function tx(string $role): string
    {
        return self::TX[$role][0];
    }

    public static function bd(string $role): string
    {
        return self::BD[$role][0];
    }

    /** <td>/<table> attributes for a background role (class + bgcolor + inline style). */
    public static function bgAttr(string $role, string $extraClass = '', string $style = ''): string
    {
        $c = self::bg($role);

        return 'class="' . trim($role . ' ' . $extraClass) . '" bgcolor="' . $c . '" style="background-color:' . $c . ';' . $style . '"';
    }

    /** Attributes for a text role. */
    public static function txAttr(string $role, string $style = '', string $extraClass = ''): string
    {
        return 'class="' . trim($role . ' ' . $extraClass) . '" style="color:' . self::tx($role) . ';' . $style . '"';
    }

    public static function darkRules(string $prefix = ''): string
    {
        $r = [];
        foreach (self::BG as $k => [$l, $d]) {
            $r[] = "{$prefix}.{$k}{background-color:{$d} !important;}";
        }
        foreach (self::TX as $k => [$l, $d]) {
            $r[] = "{$prefix}.{$k}{color:{$d} !important;}";
        }
        foreach (self::BD as $k => [$l, $d]) {
            $r[] = "{$prefix}.{$k}{border-color:{$d} !important;}";
        }
        $r[] = 'body,.bg-outer{background-color:#0c0a09 !important;}';

        return implode("\n", $r);
    }

    /** Outlook.com / Outlook app dark-mode attribute selectors. */
    public static function outlookRules(): string
    {
        return '[data-ogsc] .t-ink,[data-ogsc] .t-link{color:#f3eee6 !important;}[data-ogsb] .bg-card{background-color:#1c1814 !important;}[data-ogsb] .bg-page{background-color:#0c0a09 !important;}';
    }

    // ---- contrast helpers (WCAG) — used by tests ------------------------------------------------

    public static function luminance(string $hex): float
    {
        $h = ltrim($hex, '#');
        $f = function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $f(hexdec(substr($h, 0, 2))) + 0.7152 * $f(hexdec(substr($h, 2, 2))) + 0.0722 * $f(hexdec(substr($h, 4, 2)));
    }

    public static function contrast(string $a, string $b): float
    {
        $x = self::luminance($a);
        $y = self::luminance($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /**
     * Every foreground/background pair the components can produce, per scheme.
     * @return array<int, array{0:string,1:string,2:string,3:float}> [name, fg, bg, min]
     */
    public static function pairs(): array
    {
        $out = [];
        foreach ([0 => 'light', 1 => 'dark'] as $i => $scheme) {
            foreach (['bg-card', 'bg-soft'] as $bg) {
                foreach (['t-ink', 't-muted', 't-gold', 't-link'] as $tx) {
                    $out[] = ["$scheme $tx on $bg", self::TX[$tx][$i], self::BG[$bg][$i], 4.5];
                }
                $out[] = ["$scheme t-ok on $bg (stat, 32px)", self::TX['t-ok'][$i], self::BG[$bg][$i], 3.0];
                $out[] = ["$scheme t-warn on $bg (stat, 32px)", self::TX['t-warn'][$i], self::BG[$bg][$i], 3.0];
                $out[] = ["$scheme t-ok checklist on $bg", self::TX['t-ok'][$i], self::BG[$bg][$i], 4.5];
                $out[] = ["$scheme t-warn checklist on $bg", self::TX['t-warn'][$i], self::BG[$bg][$i], 4.5];
            }
            $out[] = ["$scheme t-ink on bg-chip", self::TX['t-ink'][$i], self::BG['bg-chip'][$i], 4.5];
            $out[] = ["$scheme t-gold on bg-chip", self::TX['t-gold'][$i], self::BG['bg-chip'][$i], 4.5];
            $out[] = ["$scheme t-warn on bg-warn", self::TX['t-warn'][$i], self::BG['bg-warn'][$i], 4.5];
            $out[] = ["$scheme t-ink on bg-warn", self::TX['t-ink'][$i], self::BG['bg-warn'][$i], 4.5];
            $out[] = ["$scheme t-ok on bg-ok", self::TX['t-ok'][$i], self::BG['bg-ok'][$i], 4.5];
            $out[] = ["$scheme t-ink on bg-ok", self::TX['t-ink'][$i], self::BG['bg-ok'][$i], 4.5];
            $out[] = ["$scheme t-foot on bg-page", self::TX['t-foot'][$i], self::BG['bg-page'][$i], 4.5];
            $out[] = ["$scheme hero text", self::HERO_TX, self::BG['bg-hero'][$i], 4.5];
            $out[] = ["$scheme hero muted", self::HERO_MUTED, self::BG['bg-hero'][$i], 4.5];
            $out[] = ["$scheme hero gold", self::HERO_GOLD, self::BG['bg-hero'][$i], 4.5];
            $out[] = ["$scheme hero warn kicker", self::HERO_WARN, self::BG['bg-hero'][$i], 4.5];
        }
        $out[] = ['button', self::BTN_TX, self::BTN_BG, 4.5];

        return $out;
    }
}
