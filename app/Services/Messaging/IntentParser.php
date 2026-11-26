<?php

namespace App\Services\Messaging;

/**
 * Rule-based Hebrew reply classifier (PLAN §6.4). Returns an intent + a 1-click suggestion for the host.
 * e.g. "מגיעים רק 2 במקום 3, אני צמחונית" → rsvp_update {status: 1, party_size: 2, meal: "צמחוני"}.
 */
class IntentParser
{
    private const NUMBERS = ['אחד' => 1, 'אחת' => 1, 'שניים' => 2, 'שתיים' => 2, 'שנינו' => 2, 'זוג' => 2, 'שלושה' => 3, 'שלוש' => 3, 'ארבעה' => 4, 'ארבע' => 4, 'חמישה' => 5, 'חמש' => 5, 'שישה' => 6, 'שש' => 6];

    public static function isOptOut(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match('/^(הסר|הסירו|הסרה|להסיר|stop|unsubscribe|לא לשלוח|הסירו אותי)[\s!.]*$/u', $t);
    }

    public static function parse(string $text): array
    {
        $t = trim($text);
        $lower = mb_strtolower($t);
        if (self::isOptOut($t)) {
            return ['intent' => 'opt_out', 'suggestion' => null];
        }
        $s = [];
        if (preg_match('/(לא\s+(נוכל|אוכל|נגיע|מגיע|מגיעה|מגיעים|נבוא|אבוא)|לצערנו|לצערי|לא נצליח|לא אצליח)/u', $t)) {
            $s['status'] = 2;
        } elseif (preg_match('/(אולי|לא בטוח|לא בטוחה|עוד לא יודע|עוד לא יודעת)/u', $t)) {
            $s['status'] = 3;
        } elseif (preg_match('/(מגיע|מגיעה|מגיעים|נגיע|נהיה|באים|אגיע|נבוא|אישור הגעה|מאשר|מאשרת|מאשרים)/u', $t)) {
            $s['status'] = 1;
        }

        $n = null;
        if (preg_match('/רק\s+(\d{1,2})/u', $t, $m) || preg_match('/(\d{1,2})\s+במקום/u', $t, $m) || preg_match('/(?:מגיעים|נגיע|נהיה|באים)\s+(\d{1,2})/u', $t, $m) || preg_match('/(\d{1,2})\s+(?:אנשים|אורחים|מוזמנים|נפשות)/u', $t, $m)) {
            $n = (int) $m[1];
        } else {
            foreach (self::NUMBERS as $word => $val) {
                if (preg_match('/(רק|נהיה|מגיעים|נגיע)\s+' . $word . '(\s|$|,|\.)/u', $t)) {
                    $n = $val;
                    break;
                }
            }
        }
        if ($n !== null && $n >= 1 && $n <= 30) {
            $s['party_size'] = $n;
            $s['status'] ??= 1;
        }

        if (preg_match('/טבעונ/u', $t)) {
            $s['meal'] = 'טבעוני';
        } elseif (preg_match('/צמחונ/u', $t)) {
            $s['meal'] = 'צמחוני';
        } elseif (preg_match('/(ללא גלוטן|צליאק)/u', $t)) {
            $s['meal'] = 'ללא גלוטן';
        }
        if (preg_match('/(אלרגי[הי]?\s+ל[^\s,.]+|אלרגיה[^,.]*)/u', $t, $m)) {
            $s['allergies'] = mb_substr(trim($m[1]), 0, 120);
        }

        if ($s) {
            return ['intent' => 'rsvp_update', 'suggestion' => $s];
        }
        if (str_contains($t, '?') || preg_match('/^(איפה|מתי|איך|האם|כמה|מה|יש)\b/u', $lower)) {
            return ['intent' => 'question', 'suggestion' => null];
        }

        return ['intent' => 'other', 'suggestion' => null];
    }
}
