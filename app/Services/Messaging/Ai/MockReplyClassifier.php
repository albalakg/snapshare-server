<?php

namespace App\Services\Messaging\Ai;

/**
 * Deterministic stand-in for local QA (WHATSAPP_AI_PROVIDER=mock): starts from the Hebrew rules and also
 * understands a few phrasings the rules miss (e.g. "סה״כ ארבעה", "+1", "עם בן הזוג"), like a model would.
 */
class MockReplyClassifier implements ReplyClassifier
{
    private const WORDS = ['אחד' => 1, 'אחת' => 1, 'שניים' => 2, 'שתיים' => 2, 'שלושה' => 3, 'שלוש' => 3, 'ארבעה' => 4, 'ארבע' => 4, 'חמישה' => 5, 'חמש' => 5, 'שישה' => 6, 'שש' => 6];

    public function name(): string
    {
        return 'mock';
    }

    public function model(): ?string
    {
        return 'mock-classifier';
    }

    public function classify(string $text, array $context): array
    {
        $rules = (array) ($context['rules'] ?? []);
        $s = (array) ($rules['suggestion'] ?? []);
        $intent = (string) ($rules['intent'] ?? 'other');

        if (!isset($s['party_size'])) {
            if (preg_match('/(?:סה["״]?כ|ביחד|בסך הכל)\s*(\d{1,2})/u', $text, $m)) {
                $s['party_size'] = (int) $m[1];
            } elseif (preg_match('/(?:סה["״]?כ|ביחד|בסך הכל)\s*(' . implode('|', array_keys(self::WORDS)) . ')/u', $text, $m)) {
                $s['party_size'] = self::WORDS[$m[1]];
            } elseif (preg_match('/(\+1|פלוס אחד|עם בן הזוג|עם בת הזוג|עם אשתי|עם בעלי)/u', $text)) {
                $s['party_size'] = 2;
            }
        }
        if (!isset($s['status']) && preg_match('/(בשמחה|בטח|ניפגש|נשמח להגיע|שמור לנו מקום)/u', $text)) {
            $s['status'] = 1;
        }
        if (in_array($intent, ['other'], true) && preg_match('/(מזל טוב|מזל"ט|תודה רבה|ברכות)/u', $text) && !$s) {
            $intent = 'greeting';
        }
        $out = ReplyClassifierPrompt::sanitize(['intent' => $s ? 'rsvp_update' : $intent, 'suggestion' => $s ?: null, 'confidence' => $s ? 0.86 : 0.6], $context);
        $out['note'] = $this->note($out);

        return $out;
    }

    private function note(array $out): string
    {
        $s = (array) ($out['suggestion'] ?? []);
        if (!$s) {
            return match ($out['intent']) {
                'question' => 'האורח/ת שואל/ת שאלה — כדאי לענות ידנית',
                'greeting' => 'ברכה / תודה — אין שינוי באישור ההגעה',
                default    => 'לא זוהה שינוי באישור ההגעה',
            };
        }
        $parts = [];
        if (isset($s['status'])) {
            $parts[] = [1 => 'מגיע/ה', 2 => 'לא מגיע/ה', 3 => 'אולי'][$s['status']];
        }
        if (isset($s['party_size'])) {
            $parts[] = $s['party_size'] . ' אורחים';
        }
        if (isset($s['meal'])) {
            $parts[] = 'מנה: ' . $s['meal'];
        }
        if (isset($s['allergies'])) {
            $parts[] = $s['allergies'];
        }

        return implode(' · ', $parts);
    }
}
