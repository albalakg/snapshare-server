<?php

namespace App\Services\Messaging;

use App\Models\EventGuest;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSendRecipient;
use App\Services\Guests\GuestCrmService;
use App\Services\Guests\PhoneNormalizer;
use App\Services\Messaging\Providers\ProviderFactory;
use App\Services\Messaging\Providers\WhatsAppProviderInterface;
use App\Services\Messaging\Ai\ReplyClassifierFactory;
use Exception;
use Throwable;
use Illuminate\Support\Facades\Log;

/**
 * Inbound WhatsApp messages: guest matching, opt-out, RSVP bot (24h service window, free), reply classifier.
 */
class InboundService
{
    public function __construct(private ?WhatsAppProviderInterface $wa = null, private ?RsvpService $rsvp = null)
    {
        $this->wa ??= ProviderFactory::whatsapp();
        $this->rsvp ??= new RsvpService();
    }

    /** Meta webhook payload (entry[].changes[].value). */
    public function handleMetaPayload(array $payload): array
    {
        $stats = ['messages' => 0, 'statuses' => 0, 'templates' => 0];
        $dispatcher = new Dispatcher($this->wa);
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $field = $change['field'] ?? 'messages';
                $value = (array) ($change['value'] ?? []);
                if ($field === 'message_template_status_update') {
                    $this->templateStatus($value);
                    $stats['templates']++;
                    continue;
                }
                foreach ((array) ($value['statuses'] ?? []) as $st) {
                    $err = $st['errors'][0] ?? [];
                    $dispatcher->applyStatus((string) ($st['id'] ?? ''), (string) ($st['status'] ?? ''), isset($err['code']) ? (string) $err['code'] : null, $err['title'] ?? null);
                    $stats['statuses']++;
                }
                foreach ((array) ($value['messages'] ?? []) as $msg) {
                    $type = $msg['type'] ?? 'text';
                    $text = match ($type) {
                        'text' => (string) ($msg['text']['body'] ?? ''),
                        'button' => (string) ($msg['button']['text'] ?? ''),
                        'interactive' => (string) ($msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? ''),
                        default => '[' . $type . ']',
                    };
                    $payloadId = match ($type) {
                        'button' => $msg['button']['payload'] ?? null,
                        'interactive' => $msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? null,
                        default => null,
                    };
                    $this->handle((string) ($msg['from'] ?? ''), $text, $payloadId, $msg['context']['id'] ?? null, $msg['id'] ?? null);
                    $stats['messages']++;
                }
            }
        }

        return $stats;
    }

    /**
     * @return array{matched: bool, guest_id?: int, intent?: string}
     */
    public function handle(string $from, string $text, ?string $payload = null, ?string $context_id = null, ?string $provider_id = null): array
    {
        if ($provider_id && WhatsAppMessage::where('provider_message_id', $provider_id)->exists()) {
            return ['matched' => false, 'duplicate' => true];
        }
        [$guest, $recipient] = $this->match($from, $payload, $context_id);
        if (!$guest) {
            Log::info('[whatsapp-inbound] unmatched sender', ['from_suffix' => substr($from, -3)]);

            return ['matched' => false];
        }

        $conv = WhatsAppConversation::firstOrCreate(['event_id' => $guest->event_id, 'guest_id' => $guest->id]);
        $conv->last_inbound_at = now();
        $conv->save();
        if ($recipient && !$recipient->replied_at) {
            $recipient->update(['replied_at' => now()]);
        }

        $action = $payload ? explode(':', $payload)[0] : null;
        $parsed = $action ? ['intent' => 'rsvp_update', 'suggestion' => null] : IntentParser::parse($text);
        $msg = WhatsAppMessage::create([
            'event_id' => $guest->event_id, 'guest_id' => $guest->id, 'send_id' => $recipient?->send_id, 'direction' => 'in',
            'kind' => $payload ? 'button' : 'text', 'body' => mb_substr($text, 0, 4000), 'payload' => $payload ? ['payload' => $payload] : null,
            'provider_message_id' => $provider_id, 'context_id' => $context_id, 'intent' => $parsed['intent'], 'suggestion' => $parsed['suggestion'],
        ]);

        $settings = MessagingContext::settings((int) $guest->event_id);
        $cfg = MessagingContext::rsvpConfig($settings);

        // Opt-out (button or text)
        if ($action === 'opt_out' || $parsed['intent'] === 'opt_out') {
            $this->optOut($guest);
            $msg->update(['handled' => 'bot']);
            $this->reply($guest, 'הוסרת מרשימת התפוצה של האירוע. לא נשלח הודעות נוספות 🙏');

            return ['matched' => true, 'guest_id' => $guest->id, 'intent' => 'opt_out'];
        }

        // RSVP buttons start the bot
        if (in_array($action, ['rsvp_yes', 'rsvp_no', 'rsvp_maybe'], true)) {
            $status = substr($action, 5);
            $this->rsvp->apply($guest, ['status' => $status], 'whatsapp');
            $msg->update(['handled' => 'bot']);
            if ($status === 'yes') {
                $this->nextStep($guest, $conv, $cfg, null);
            } else {
                $conv->update(['step' => null]);
                $this->reply($guest, $status === 'no' ? $cfg['thank_you_no'] : $cfg['thank_you_maybe']);
            }

            return ['matched' => true, 'guest_id' => $guest->id, 'intent' => 'rsvp_' . $status];
        }

        // Bot conversation in progress
        if ($conv->step) {
            if ($this->continueBot($guest, $conv, $cfg, $text)) {
                $msg->update(['handled' => 'bot', 'intent' => 'rsvp_update', 'suggestion' => null]);

                return ['matched' => true, 'guest_id' => $guest->id, 'intent' => 'bot'];
            }
        }

        // Free text the bot did not handle → optional AI classifier (suggestion for the host only; never applied here).
        if (!$payload) {
            $parsed = $this->classify($msg, $guest, $text, $parsed, $cfg);
        }

        return ['matched' => true, 'guest_id' => $guest->id, 'intent' => $parsed['intent']];
    }

    /**
     * AI classifier with fallback to the Hebrew rules. Opt-out stays rules-only (handled above).
     */
    private function classify(WhatsAppMessage $msg, EventGuest $guest, string $text, array $rules, array $cfg): array
    {
        $msg->update(['classifier' => 'rules']);
        $classifier = ReplyClassifierFactory::make();
        if (!$classifier || trim($text) === '') {
            return $rules;
        }
        try {
            $out = $classifier->classify($text, [
                'rules' => $rules,
                'guest_first_name' => preg_split('/\s+/u', trim((string) $guest->full_name))[0] ?? null,
                'party_size' => (int) $guest->party_size,
                'max_party_size' => (int) ($cfg['max_party_size'] ?? 10),
                'meal_options' => (array) ($cfg['meals'] ?? []),
            ]);
        } catch (Throwable $e) {
            Log::warning('[whatsapp-inbound] AI classifier failed, using rules', ['error' => $e->getMessage()]);

            return $rules;
        }
        $msg->update([
            'intent' => $out['intent'], 'suggestion' => $out['suggestion'], 'classifier' => $classifier->name(),
            'confidence' => $out['confidence'], 'suggestion_note' => $out['note'],
        ]);

        return $out;
    }

    private function nextStep(EventGuest $guest, WhatsAppConversation $conv, array $cfg, ?string $after): void
    {
        $order = ['party', 'meal', 'allergies'];
        $enabled = ['party' => (bool) $cfg['ask_party_size'], 'meal' => (bool) $cfg['ask_meal'] && !empty($cfg['meals']), 'allergies' => (bool) $cfg['ask_allergies']];
        $start = $after === null ? 0 : array_search($after, $order, true) + 1;
        for ($i = $start; $i < count($order); $i++) {
            $step = $order[$i];
            if (!$enabled[$step]) {
                continue;
            }
            $conv->update(['step' => $step]);
            if ($step === 'party') {
                $this->reply($guest, "מעולה! 🎉 כמה תגיעו בסך הכול? (השיבו במספר, למשל 2)");
            } elseif ($step === 'meal') {
                $lines = [];
                foreach (array_values($cfg['meals']) as $n => $meal) {
                    $lines[] = ($n + 1) . '. ' . $meal;
                }
                $this->reply($guest, "איזו מנה תעדיפו?\n" . implode("\n", $lines) . "\n(השיבו במספר)");
            } else {
                $this->reply($guest, 'יש אלרגיות או רגישויות שכדאי שנדע? (אם לא, השיבו "אין")');
            }

            return;
        }
        $conv->update(['step' => null]);
        $this->reply($guest, $cfg['thank_you_yes']);
    }

    private function continueBot(EventGuest $guest, WhatsAppConversation $conv, array $cfg, string $text): bool
    {
        $t = trim($text);
        switch ($conv->step) {
            case 'party':
                if (!preg_match('/(\d{1,2})/', $t, $m)) {
                    $this->reply($guest, 'לא הצלחתי להבין 🙂 השיבו במספר בלבד, למשל 2');

                    return true;
                }
                $this->rsvp->apply($guest, ['party_size' => (int) $m[1]], 'whatsapp');
                $this->nextStep($guest, $conv, $cfg, 'party');

                return true;
            case 'meal':
                $meals = array_values($cfg['meals']);
                $choice = null;
                if (preg_match('/^(\d{1,2})$/', $t, $m) && isset($meals[(int) $m[1] - 1])) {
                    $choice = $meals[(int) $m[1] - 1];
                } else {
                    foreach ($meals as $meal) {
                        if (mb_stripos($t, $meal) !== false) {
                            $choice = $meal;
                        }
                    }
                }
                $this->rsvp->apply($guest, ['meal' => $choice ?? mb_substr($t, 0, 60)], 'whatsapp');
                $this->nextStep($guest, $conv, $cfg, 'meal');

                return true;
            case 'allergies':
                $this->rsvp->apply($guest, ['allergies' => $t], 'whatsapp');
                $this->nextStep($guest, $conv, $cfg, 'allergies');

                return true;
        }

        return false;
    }

    public function optOut(EventGuest $guest): void
    {
        if (!$guest->wa_opted_out_at) {
            $guest->wa_opted_out_at = now();
            $guest->save();
        }
        WhatsAppSendRecipient::where('event_id', $guest->event_id)->where('guest_id', $guest->id)->where('status', 'pending')
            ->update(['status' => 'skipped', 'skip_reason' => 'opted_out', 'updated_at' => now()]);
    }

    /** Free-form reply inside the 24h window (bot or host). */
    public function reply(EventGuest $guest, string $body, string $by = 'bot'): ?WhatsAppMessage
    {
        try {
            $to = PhoneNormalizer::toWhatsApp((string) $guest->phone);
        } catch (Exception) {
            return null;
        }
        $res = $this->wa->sendText($to, $body);

        return WhatsAppMessage::create([
            'event_id' => $guest->event_id, 'guest_id' => $guest->id, 'direction' => 'out', 'kind' => 'text', 'body' => $body,
            'provider_message_id' => $res->id, 'status' => $res->ok ? 'sent' : 'failed', 'handled' => $by,
            'payload' => $res->ok ? null : ['error' => $res->error],
        ]);
    }

    /** @return array{0: ?EventGuest, 1: ?WhatsAppSendRecipient} */
    private function match(string $from, ?string $payload, ?string $context_id): array
    {
        // 1) payload carries the recipient id (rsvp_yes:123)
        if ($payload && preg_match('/:(\d+)$/', $payload, $m)) {
            $r = WhatsAppSendRecipient::find((int) $m[1]);
            if ($r && ($g = EventGuest::find($r->guest_id)) && $this->samePhone($g, $from)) {
                return [$g, $r];
            }
        }
        // 2) reply context → the outbound message
        if ($context_id) {
            $r = WhatsAppSendRecipient::where('provider_message_id', $context_id)->first();
            if ($r && ($g = EventGuest::find($r->guest_id))) {
                return [$g, $r];
            }
        }
        // 3) phone hash → latest outbound to that phone across events
        try {
            $crm = new GuestCrmService();
            $hashes = $crm->phoneHashCandidates($crm->canonicalPhone($from));
        } catch (Exception) {
            return [null, null];
        }
        $guestIds = EventGuest::whereIn('phone_hash', $hashes)->pluck('id');
        if ($guestIds->isEmpty()) {
            return [null, null];
        }
        $r = WhatsAppSendRecipient::whereIn('guest_id', $guestIds)->whereNotNull('sent_at')->orderByDesc('sent_at')->first();
        if ($r) {
            return [EventGuest::find($r->guest_id), $r];
        }
        $conv = WhatsAppConversation::whereIn('guest_id', $guestIds)->orderByDesc('last_inbound_at')->first();

        return [$conv ? EventGuest::find($conv->guest_id) : null, null];
    }

    private function samePhone(EventGuest $g, string $from): bool
    {
        try {
            return PhoneNormalizer::toWhatsApp((string) $g->phone) === PhoneNormalizer::toWhatsApp($from);
        } catch (Exception) {
            return false;
        }
    }

    private function templateStatus(array $value): void
    {
        $id = (string) ($value['message_template_id'] ?? '');
        $event = strtolower((string) ($value['event'] ?? ''));
        if ($id === '' || !in_array($event, ['approved', 'rejected', 'pending'], true)) {
            return;
        }
        \App\Models\WhatsAppTemplate::where('meta_template_id', $id)->update([
            'meta_status' => $event, 'meta_reason' => $value['reason'] ?? null, 'reviewed_at' => now(),
        ]);
    }
}
