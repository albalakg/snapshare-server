<?php

namespace App\Services\Messaging\Ai;

class ReplyClassifierPrompt
{
    public static function system(): string
    {
        return <<<'TXT'
You classify a wedding/event guest's free-text WhatsApp reply (usually Hebrew) for the host's RSVP inbox.
The host reviews every suggestion before anything changes; never invent facts that are not in the message.
Return ONLY a JSON object:
{"intent": "rsvp_update" | "question" | "greeting" | "other",
 "suggestion": null | {"status": 1|2|3, "party_size": int, "meal": string, "allergies": string},
 "confidence": number 0..1,
 "note": short Hebrew summary for the host (max 120 chars)}
status: 1 = coming, 2 = not coming, 3 = maybe. Include only fields the guest clearly stated.
party_size = total people coming including the guest (1..30). meal: one of the given meal options when it matches.
Questions (where/when/parking/gift) → intent "question" with suggestion null.
TXT;
    }

    public static function user(string $text, array $context): string
    {
        return json_encode([
            'message'         => mb_substr($text, 0, 1000),
            'guest_first_name' => $context['guest_first_name'] ?? null,
            'invited_party_size' => $context['party_size'] ?? null,
            'max_party_size'  => $context['max_party_size'] ?? null,
            'meal_options'    => array_values((array) ($context['meal_options'] ?? [])),
            'event_title'     => $context['event_title'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Keep only fields the host can 1-click apply; clamp values. */
    public static function sanitize(array $data, array $context): array
    {
        $intent = in_array($data['intent'] ?? null, ['rsvp_update', 'question', 'greeting', 'other'], true) ? $data['intent'] : 'other';
        $max = max(1, min(30, (int) ($context['max_party_size'] ?? 30)));
        $s = [];
        $in = is_array($data['suggestion'] ?? null) ? $data['suggestion'] : [];
        if (isset($in['status']) && in_array((int) $in['status'], [1, 2, 3], true)) {
            $s['status'] = (int) $in['status'];
        }
        if (isset($in['party_size']) && is_numeric($in['party_size']) && (int) $in['party_size'] >= 1) {
            $s['party_size'] = min($max, (int) $in['party_size']);
            $s['status'] ??= 1;
        }
        foreach (['meal' => 60, 'allergies' => 120] as $k => $len) {
            if (isset($in[$k]) && is_string($in[$k]) && trim($in[$k]) !== '') {
                $s[$k] = mb_substr(trim($in[$k]), 0, $len);
            }
        }
        if (($s['status'] ?? null) === 2) {
            unset($s['party_size'], $s['meal'], $s['allergies']);
        }
        if ($s) {
            $intent = 'rsvp_update';
        } elseif ($intent === 'rsvp_update') {
            $intent = 'other';
        }
        $conf = isset($data['confidence']) && is_numeric($data['confidence']) ? max(0.0, min(1.0, (float) $data['confidence'])) : null;
        $note = isset($data['note']) && is_string($data['note']) ? mb_substr(trim($data['note']), 0, 120) : null;

        return ['intent' => $intent, 'suggestion' => $s ?: null, 'confidence' => $conf, 'note' => $note ?: null];
    }
}
