<?php

namespace App\Services\Messaging;

use App\Models\Event;
use App\Models\EventGuest;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use App\Services\Guests\PhoneNormalizer;
use App\Services\Messaging\Providers\ProviderFactory;
use App\Services\Messaging\Providers\ProviderResult;
use App\Services\Messaging\Providers\SmsProviderInterface;
use App\Services\Messaging\Providers\WhatsAppProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatcher contract (PLAN §7.1):
 * 1. select due sends; 2. re-read send + template + guest + seating right before each recipient;
 * 3. claim with UPDATE … SET status='sending' WHERE status='pending' AND locked=0;
 * 4. on provider accept → sent, locked, credited, content_version_sent;
 * pause / cancel / reschedule between two recipients stop the loop; quiet hours + Shabbat hold the send.
 */
class Dispatcher
{
    public function __construct(
        private ?WhatsAppProviderInterface $wa = null,
        private ?SmsProviderInterface $sms = null,
        private ?QuotaService $quota = null,
        private ?AudienceResolver $audience = null,
    ) {
        $this->wa ??= ProviderFactory::whatsapp();
        $this->sms ??= ProviderFactory::sms();
        $this->quota ??= new QuotaService();
        $this->audience ??= new AudienceResolver();
    }

    /** Scheduler entry point. */
    public function tick(): array
    {
        if (!MessagingContext::enabled()) {
            return ['skipped' => 'disabled'];
        }
        $templatesChanged = (new TemplateService($this->wa))->syncPending();
        $materialized = (new AutomationService())->tick();
        $ids = WhatsAppSend::whereIn('status', ['scheduled', 'sending', 'review'])
            ->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(50)->pluck('id');
        $sent = 0;
        foreach ($ids as $id) {
            $sent += $this->processSend((int) $id);
        }

        return ['templates' => $templatesChanged, 'automations' => $materialized, 'sends' => count($ids), 'messages' => $sent];
    }

    /** @return int messages accepted by the provider */
    public function processSend(int $send_id): int
    {
        $send = WhatsAppSend::find($send_id);
        if (!$send || !$this->active($send)) {
            return 0;
        }
        $event = Event::find($send->event_id);
        if (!$event) {
            return 0;
        }
        $settings = MessagingContext::settings($event->id);

        if (!$this->templateReady($send)) {
            return 0;
        }
        if (!$send->override_quiet && !QuietHoursGuard::forSettings($settings)->allowed(now())) {
            return 0; // held until the next allowed slot
        }
        if ($send->audience_mode === 'dynamic') {
            $this->syncDynamicAudience($send, $event, $settings);
        }

        // Fresh resolver right before sending (seating labels / event details are current).
        $resolver = new VariableResolver($event, $settings);
        $accepted = 0;
        $batch = (int) config('whatsapp_messaging.dispatch_batch', 200);
        $ids = WhatsAppSendRecipient::where('send_id', $send->id)->where('status', 'pending')->where('locked', false)
            ->orderBy('id')->limit($batch)->pluck('id');

        foreach ($ids as $rid) {
            $fresh = WhatsAppSend::find($send->id); // re-read before each recipient
            if (!$fresh || !$this->active($fresh) || !$this->templateReady($fresh)) {
                break;
            }
            if (!$fresh->override_quiet && !QuietHoursGuard::forSettings($settings)->allowed(now())) {
                break;
            }
            $claimed = WhatsAppSendRecipient::where('id', $rid)->where('status', 'pending')->where('locked', false)
                ->update(['status' => 'sending', 'claimed_version' => $fresh->version, 'updated_at' => now()]);
            if ($claimed !== 1) {
                continue; // edited / cancelled meanwhile
            }
            if ($fresh->status === 'scheduled') {
                $fresh->status = 'sending';
                $fresh->started_at ??= now();
                $fresh->save();
            }
            try {
                $accepted += $this->deliverOne($fresh, WhatsAppSendRecipient::find($rid), $event, $settings, $resolver) ? 1 : 0;
            } catch (Throwable $e) {
                Log::error('[whatsapp-dispatch] ' . $e->getMessage());
                WhatsAppSendRecipient::where('id', $rid)->where('status', 'sending')->update(['status' => 'failed', 'error' => 'internal_error']);
            }
        }

        $this->finalize($send->id);

        return $accepted;
    }

    private function deliverOne(WhatsAppSend $send, WhatsAppSendRecipient $r, Event $event, $settings, VariableResolver $resolver): bool
    {
        $guest = EventGuest::find($r->guest_id);
        $skip = function (string $reason) use ($r) {
            $r->update(['status' => 'skipped', 'skip_reason' => $reason]);

            return false;
        };
        if (!$guest || !$guest->phone) {
            return $skip('no_phone');
        }
        if ($guest->wa_opted_out_at) {
            return $skip('opted_out');
        }
        if ($send->audience_mode === 'dynamic' && !$this->audience->matches($guest, (array) $send->segment)) {
            return $skip('segment_changed'); // smart stop condition (e.g. guest confirmed meanwhile)
        }
        $usage = WhatsAppSendRecipient::where('event_id', $event->id)->where('guest_id', $guest->id)->where('credited', true)->count();
        if ($usage >= $this->quota->perGuest($event, $settings)) {
            return $skip('quota');
        }

        // Optimistic check: the send may have changed after the claim — use the latest content, or release.
        $latest = WhatsAppSend::find($send->id);
        if (!$latest || !$this->active($latest)) {
            $r->update(['status' => $latest && $latest->status === 'paused' ? 'pending' : 'cancelled', 'claimed_version' => null]);

            return false;
        }

        try {
            $to = PhoneNormalizer::toWhatsApp((string) $guest->phone);
        } catch (Throwable) {
            return $skip('invalid_phone');
        }
        $vars = $resolver->forGuest($guest);
        $template = WhatsAppTemplate::find($latest->template_id);
        $payloads = [];
        foreach (array_values($latest->buttons ?? []) as $i => $b) {
            if (($b['type'] ?? '') === 'quick_reply') {
                $payloads[$i] = ($b['payload'] ?? 'reply') . ':' . $r->id;
            }
        }
        $rendered = VariableResolver::render($latest->body, $vars);
        $result = $this->wa->sendTemplate($to, [
            'meta_name' => $template?->meta_name, 'language' => $template?->language ?? 'he',
            'header' => $latest->header, 'body' => $latest->body, 'buttons' => $latest->buttons ?? [],
        ], $vars, $payloads);

        $channel = 'whatsapp';
        if ($result->notOnWhatsApp() && $settings->sms_fallback_enabled && ProviderFactory::smsAvailable()) {
            $result = $this->sms->send($to, $this->smsText($rendered, $vars));
            $channel = 'sms';
        }

        if ($result->ok) {
            $r->update([
                'status' => 'sent', 'channel' => $channel, 'locked' => true, 'credited' => true, 'provider_message_id' => $result->id,
                'content_version_sent' => $latest->content_version, 'sent_at' => now(), 'error' => null,
            ]);
            WhatsAppMessage::create([
                'event_id' => $event->id, 'guest_id' => $guest->id, 'send_id' => $latest->id, 'direction' => 'out',
                'kind' => $channel === 'sms' ? 'sms' : 'template', 'body' => trim(VariableResolver::render($latest->header, $vars) . "\n" . $rendered),
                'provider_message_id' => $result->id, 'status' => 'sent', 'handled' => 'host',
            ]);

            return true;
        }

        $r->update(['status' => 'failed', 'error' => ($result->errorCode ? $result->errorCode . ': ' : '') . $result->error]);

        return false;
    }

    public function smsText(string $rendered, array $vars): string
    {
        $max = (int) config('whatsapp_messaging.sms.max_chars', 268);
        $link = $vars['rsvp_link'] ?? '';
        $text = trim(preg_replace('/\n{2,}/', "\n", $rendered));
        if ($link && !str_contains($text, $link)) {
            $text = mb_substr($text, 0, max(0, $max - mb_strlen($link) - 2)) . "\n" . $link;
        }

        return mb_substr($text, 0, $max);
    }

    private function active(WhatsAppSend $send): bool
    {
        return in_array($send->status, ['scheduled', 'sending', 'review'], true)
            && $send->scheduled_at && $send->scheduled_at->lte(now()->addSeconds(5));
    }

    /** Hold the send while its template waits for Meta review (D5). */
    private function templateReady(WhatsAppSend $send): bool
    {
        $tpl = $send->template_id ? WhatsAppTemplate::find($send->template_id) : null;
        $ok = $tpl && $tpl->meta_status === 'approved';
        if (!$ok && $send->status !== 'review') {
            $send->status = 'review';
            $send->save();
        } elseif ($ok && $send->status === 'review') {
            $send->status = $send->started_at ? 'sending' : 'scheduled';
            $send->save();
        }

        return $ok;
    }

    private function syncDynamicAudience(WhatsAppSend $send, Event $event, $settings): void
    {
        $aud = $this->audience->resolve($event->id, (array) $send->segment);
        $have = WhatsAppSendRecipient::where('send_id', $send->id)->pluck('guest_id')->map(fn ($i) => (int) $i)->all();
        $new = array_values(array_diff($aud['eligible'], $have));
        if ($new) {
            (new SendService())->insertRecipients($send, $this->quota->filter($event, $settings, $new));
        }
    }

    private function finalize(int $send_id): void
    {
        DB::transaction(function () use ($send_id) {
            $send = WhatsAppSend::lockForUpdate()->find($send_id);
            if (!$send || !in_array($send->status, ['sending', 'scheduled'], true)) {
                return;
            }
            $left = WhatsAppSendRecipient::where('send_id', $send_id)->whereIn('status', ['pending', 'sending'])->count();
            if ($left > 0) {
                return;
            }
            $failed = WhatsAppSendRecipient::where('send_id', $send_id)->where('status', 'failed')->count();
            $send->status = $failed > 0 ? 'partial' : 'sent';
            $send->started_at ??= now();
            $send->completed_at = now();
            $send->save();
        });
    }

    /** Webhook delivery statuses (Meta): sent | delivered | read | failed. */
    public function applyStatus(string $provider_id, string $status, ?string $error_code = null, ?string $error = null): void
    {
        $r = WhatsAppSendRecipient::where('provider_message_id', $provider_id)->first();
        WhatsAppMessage::where('provider_message_id', $provider_id)->update(['status' => $status]);
        if (!$r) {
            return;
        }
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
        if ($status === 'failed') {
            $settings = MessagingContext::settings($r->event_id);
            if ($error_code === '131026' && $settings->sms_fallback_enabled && $r->channel === 'whatsapp' && ProviderFactory::smsAvailable()) {
                $guest = EventGuest::find($r->guest_id);
                $send = WhatsAppSend::find($r->send_id);
                $event = Event::find($r->event_id);
                if ($guest && $send && $event && $guest->phone) {
                    $vars = (new VariableResolver($event, $settings))->forGuest($guest);
                    $res = $this->sms->send(PhoneNormalizer::toWhatsApp($guest->phone), $this->smsText(VariableResolver::render($send->body, $vars), $vars));
                    if ($res->ok) {
                        $r->update(['channel' => 'sms', 'provider_message_id' => $res->id, 'status' => 'sent', 'error' => null]);

                        return;
                    }
                }
            }
            // Meta does not bill failed messages → release the credit.
            $r->update(['status' => 'failed', 'credited' => false, 'error' => trim(($error_code ? $error_code . ': ' : '') . (string) $error) ?: 'failed']);

            return;
        }
        if (!isset($rank[$status]) || ($rank[$r->status] ?? 0) >= $rank[$status]) {
            return;
        }
        $upd = ['status' => $status];
        if ($status === 'delivered') {
            $upd['delivered_at'] = now();
        }
        if ($status === 'read') {
            $upd['read_at'] = now();
            $upd['delivered_at'] = $r->delivered_at ?? now();
        }
        $r->update($upd);
    }
}
