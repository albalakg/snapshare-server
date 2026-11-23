<?php

namespace App\Services\Trivia;

/**
 * Hebrew + English profanity filter for trivia nicknames and free-text answers.
 *
 * Word lists live in resources/profanity/*.txt (see the README there).
 * Input is normalised for case, niqqud, Hebrew final letters, leetspeak,
 * stretched letters ("fuuuck") and spacing tricks ("f u c k", "f.u.c.k").
 */
class ProfanityFilter
{
    private const HEBREW_PREFIXES = ['ו', 'ה', 'ש', 'ב', 'ל', 'כ'];

    private const MIN_STEM_AFTER_PREFIX = 4;

    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    private const LEET = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't',
        '8' => 'b', '9' => 'g', '@' => 'a', '$' => 's', '!' => 'i', '+' => 't', '|' => 'l',
    ];

    /** @var array<string,array>|null cache per lists directory */
    private static array $cache = [];

    private array $exact = [];

    /** @var array<string,int> skeleton => shortest entry length */
    private array $skeletons = [];

    private array $prefixes = [];

    private array $contains = [];

    /** @var array<int,array<int,string>> */
    private array $phrases = [];

    private array $allow = [];

    /** @var array<int,array<int,string>> */
    private array $allowPhrases = [];

    public function __construct(?string $lists_dir = null)
    {
        $dir = $lists_dir ?? base_path('resources/profanity');
        if (!isset(self::$cache[$dir])) {
            self::$cache[$dir] = $this->loadRules($dir);
        }
        foreach (self::$cache[$dir] as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public static function forgetCache(): void
    {
        self::$cache = [];
    }

    public function isClean(?string $text): bool
    {
        return !$this->check($text)['flagged'];
    }

    public function mask(?string $text): string
    {
        return $this->check($text)['masked'];
    }

    /**
     * @return array{flagged: bool, masked: string, words: array<int,string>}
     */
    public function check(?string $text): array
    {
        $text = (string) $text;
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        // Token positions (even indexes are words, odd indexes are whitespace).
        $tokens = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1 || $part === '') {
                continue;
            }
            $tokens[] = [
                'index' => $index,
                'raw' => $part,
                'norm' => self::normalizeToken($part),
                'subs' => self::subWords($part),
            ];
        }

        $flag = [];
        $count = count($tokens);

        // Allowed phrases protect their tokens from every rule below.
        $protected = [];
        foreach ($this->allowPhrases as $phrase) {
            foreach ($this->phraseHits($tokens, $phrase) as $hit) {
                foreach ($hit as $t) {
                    $protected[$t] = true;
                }
            }
        }

        // 1) Single words (whole token, and its pieces split on punctuation).
        foreach ($tokens as $t => $token) {
            if (isset($protected[$t]) || $token['norm'] === '' || isset($this->allow[$token['norm']])) {
                continue;
            }
            if ($this->wordMatches($token['norm'])) {
                $flag[$t] = true;
                continue;
            }
            foreach ($token['subs'] as $sub) {
                if ($sub !== $token['norm'] && $this->wordMatches($sub)) {
                    $flag[$t] = true;
                    break;
                }
            }
        }

        // 2) Spaced-out letters: "f u c k", "ז ו נ ה".
        $run = [];
        for ($t = 0; $t <= $count; $t++) {
            $single = $t < $count && mb_strlen($tokens[$t]['norm']) === 1;
            if ($single) {
                $run[] = $t;
                continue;
            }
            if (count($run) >= 3) {
                $joined = implode('', array_map(fn ($i) => $tokens[$i]['norm'], $run));
                if (!isset($this->allow[$joined]) && $this->wordMatches($joined)) {
                    foreach ($run as $i) {
                        $flag[$i] = true;
                    }
                }
            }
            $run = [];
        }

        // 3) A word split in two short halves: "fu ck".
        for ($t = 0; $t < $count - 1; $t++) {
            $a = $tokens[$t]['norm'];
            $b = $tokens[$t + 1]['norm'];
            if ($a === '' || $b === '' || mb_strlen($a) > 3 || mb_strlen($b) > 3) {
                continue;
            }
            if (isset($protected[$t]) || isset($protected[$t + 1])) {
                continue;
            }
            $joined = $a . $b;
            if (mb_strlen($joined) >= 4 && !isset($this->allow[$joined]) && $this->wordMatches($joined, false)) {
                $flag[$t] = true;
                $flag[$t + 1] = true;
            }
        }

        // 4) Multi-word phrases: "כוס אמק".
        foreach ($this->phrases as $phrase) {
            foreach ($this->phraseHits($tokens, $phrase) as $hit) {
                foreach ($hit as $t) {
                    if (!isset($protected[$t])) {
                        $flag[$t] = true;
                    }
                }
            }
        }

        if ($flag === []) {
            return ['flagged' => false, 'masked' => $text, 'words' => []];
        }

        $words = [];
        foreach (array_keys($flag) as $t) {
            $raw = $tokens[$t]['raw'];
            $words[] = $raw;
            $parts[$tokens[$t]['index']] = str_repeat('*', max(1, mb_strlen($raw)));
        }

        return ['flagged' => true, 'masked' => implode('', $parts), 'words' => $words];
    }

    /**
     * Lower case, no niqqud / cantillation, final letters → regular, leetspeak → letters
     * (only for tokens with Latin letters), and every non-letter removed.
     */
    public static function normalizeToken(string $raw): string
    {
        return preg_replace('/[^\p{L}]+/u', '', self::basicNormalize($raw)) ?? '';
    }

    /** Pieces of a token split on non-letters, after normalisation. */
    private static function subWords(string $raw): array
    {
        $pieces = preg_split('/[^\p{L}]+/u', self::basicNormalize($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($pieces, fn ($p) => mb_strlen($p) >= 3));
    }

    private static function basicNormalize(string $raw): string
    {
        $value = mb_strtolower($raw, 'UTF-8');
        // Niqqud, cantillation, zero-width and bidi marks.
        $value = preg_replace('/[\x{0591}-\x{05C7}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $value) ?? '';
        $value = strtr($value, self::FINALS);
        if (preg_match('/[a-z]/', $value)) {
            $value = strtr($value, self::LEET);
        }

        return $value;
    }

    /** "fuuuck" → "fuck", "זוווונה" → "זונה". */
    private static function skeleton(string $word): string
    {
        return preg_replace('/(\p{L})\1+/u', '$1', $word) ?? $word;
    }

    private function wordMatches(string $word, bool $allow_prefix_strip = true): bool
    {
        if ($word === '' || isset($this->allow[$word])) {
            return false;
        }

        $candidates = [$word];
        if ($allow_prefix_strip) {
            $stem = $word;
            for ($i = 0; $i < 2; $i++) {
                $first = mb_substr($stem, 0, 1);
                if (!in_array($first, self::HEBREW_PREFIXES, true)) {
                    break;
                }
                $stem = mb_substr($stem, 1);
                if (mb_strlen($stem) < self::MIN_STEM_AFTER_PREFIX) {
                    break;
                }
                $candidates[] = $stem;
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($this->allow[$candidate])) {
                return false;
            }
            if (isset($this->exact[$candidate])) {
                return true;
            }
            $skeleton = self::skeleton($candidate);
            if (isset($this->skeletons[$skeleton]) && mb_strlen($candidate) > $this->skeletons[$skeleton]) {
                return true;
            }
            foreach ($this->prefixes as $prefix) {
                if (str_starts_with($candidate, $prefix) || str_starts_with($skeleton, $prefix)) {
                    return true;
                }
            }
            foreach ($this->contains as $needle) {
                if (str_contains($candidate, $needle) || str_contains($skeleton, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int,array> $tokens
     * @param array<int,string> $phrase
     * @return array<int,array<int,int>>
     */
    private function phraseHits(array $tokens, array $phrase): array
    {
        $hits = [];
        $length = count($phrase);
        $words = array_values(array_filter(
            array_keys($tokens),
            fn ($t) => $tokens[$t]['norm'] !== ''
        ));
        for ($i = 0; $i + $length <= count($words); $i++) {
            $match = true;
            for ($k = 0; $k < $length; $k++) {
                $norm = $tokens[$words[$i + $k]]['norm'];
                if ($norm !== $phrase[$k] && self::skeleton($norm) !== self::skeleton($phrase[$k])) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $hits[] = array_slice($words, $i, $length);
            }
        }

        return $hits;
    }

    private function loadRules(string $dir): array
    {
        $rules = [
            'exact' => [],
            'skeletons' => [],
            'prefixes' => [],
            'contains' => [],
            'phrases' => [],
            'allow' => [],
            'allowPhrases' => [],
        ];

        foreach (glob(rtrim($dir, '/\\') . '/*.txt') ?: [] as $file) {
            $is_allow = basename($file) === 'allow.txt';
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim(preg_replace('/#.*$/u', '', $line) ?? '');
                if ($line === '') {
                    continue;
                }

                $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($words) > 1) {
                    $phrase = array_values(array_filter(array_map(fn ($w) => self::normalizeToken($w), $words)));
                    if (count($phrase) > 1) {
                        $rules[$is_allow ? 'allowPhrases' : 'phrases'][] = $phrase;
                    }
                    continue;
                }

                $starts = str_starts_with($line, '*');
                $ends = str_ends_with($line, '*');
                $word = self::normalizeToken(trim($line, '*'));
                if ($word === '') {
                    continue;
                }

                if ($is_allow) {
                    $rules['allow'][$word] = true;
                } elseif ($starts && $ends) {
                    $rules['contains'][] = $word;
                } elseif ($ends) {
                    $rules['prefixes'][] = $word;
                } else {
                    $rules['exact'][$word] = true;
                    $skeleton = self::skeleton($word);
                    $length = mb_strlen($word);
                    $rules['skeletons'][$skeleton] = min($rules['skeletons'][$skeleton] ?? PHP_INT_MAX, $length);
                }
            }
        }

        $rules['prefixes'] = array_values(array_unique($rules['prefixes']));
        $rules['contains'] = array_values(array_unique($rules['contains']));

        return $rules;
    }
}
