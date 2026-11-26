<?php

namespace App\Services\Messaging;

use App\Models\Event;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use Carbon\Carbon;

/**
 * Automations materialize into the same editable queue (one whatsapp_sends row per rule, audience_mode=dynamic).
 * The host can edit / reschedule / pause the occurrence like any send. Untouched occurrences (version 1) follow
 * event date changes on the next tick.
 */
class AutomationService
{
    public function list(int $event_id, int $user_id): array
    {
        $event = MessagingContext::event($event_id, $user_id);
        $this->ensureDefaults($event->id);

        return ['automations' => WhatsAppAutomation::where('event_id', $event->id)->orderBy('offset_days')->orderBy('send_time')->get()
            ->map(fn ($a) => $this->format($a, $event))->values(), 'event_date' => $event->starts_at ? Carbon::parse($event->starts_at, 'UTC')->toIso8601String() : null];
    }

    public function update(int $event_id, int $automation_id, int $user_id, array $data): array
    {
        $event = MessagingContext::event($event_id, $user_id);
        $this->ensureDefaults($event->id);
        $a = WhatsAppAutomation::where('event_id', $event->id)->where('id', $automation_id)->first();
        if (!$a) {
            throw new MessagingHttpException('Automation not found', 404);
        }
        if (array_key_exists('offset_days', $data)) {
            $a->offset_days = max(-365, min(60, (int) $data['offset_days']));
        }
        if (array_key_exists('send_time', $data)) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $data['send_time'])) {
                throw new MessagingHttpException('Invalid time', 422);
            }
            $a->send_time = $data['send_time'];
        }
        if (array_key_exists('segment', $data)) {
            $a->segment = AudienceResolver::normalize((array) $data['segment']);
        }
        $wasEnabled = $a->enabled;
        if (array_key_exists('enabled', $data)) {
            $a->enabled = (bool) $data['enabled'];
        }
        $a->save();

        $send = $a->send_id ? WhatsAppSend::find($a->send_id) : null;
        if (!$a->enabled && $send && $send->isEditable()) {
            $this->cancelOccurrence($send, $user_id);
            $a->send_id = null;
            $a->save();
        } elseif ($a->enabled) {
            if ($send && $send->isEditable()) {
                $this->resync($a, $event, $send, $user_id, true);
            } elseif (!$send || !$wasEnabled) {
                $this->materialize($a, $event, $user_id);
            }
        }

        return $this->format($a->fresh(), $event);
    }

    /** Called by the scheduler tick. */
    public function tick(): int
    {
        $n = 0;
        foreach (WhatsAppAutomation::where('enabled', true)->limit(500)->get() as $a) {
            $event = Event::find($a->event_id);
            if (!$event) {
                continue;
            }
            $send = $a->send_id ? WhatsAppSend::find($a->send_id) : null;
            if (!$send) {
                $n += $this->materialize($a, $event, null) ? 1 : 0;
            } elseif ($send->isEditable() && (int) $send->version === 1 && !$send->started_at) {
                $this->resync($a, $event, $send, null, false);
            }
        }

        return $n;
    }

    public function runAt(WhatsAppAutomation $a, Event $event): ?Carbon
    {
        if (!$event->starts_at) {
            return null;
        }
        $tz = config('whatsapp_messaging.timezone', 'Asia/Jerusalem');
        $day = Carbon::parse($event->starts_at, 'UTC')->setTimezone($tz)->startOfDay()->addDays($a->offset_days);
        [$h, $m] = array_map('intval', explode(':', $a->send_time));

        return Carbon::create($day->year, $day->month, $day->day, $h, $m, 0, $tz)->utc();
    }

    private function materialize(WhatsAppAutomation $a, Event $event, ?int $user_id): bool
    {
        $at = $this->runAt($a, $event);
        if (!$at || $at->lt(now())) {
            return false;
        }
        $tpl = TemplateCatalog::get($a->template_key);
        if (!$tpl) {
            return false;
        }
        $settings = MessagingContext::settings($event->id);
        $guard = QuietHoursGuard::forSettings($settings);
        if (!$guard->allowed($at)) {
            $at = $guard->nextAllowed($at);
        }
        $created = (new SendService())->createFor($event, $user_id, [
            'template_key' => $a->template_key, 'title' => $a->title, 'segment' => $a->segment ?? [],
            'scheduled_at' => $at->toIso8601String(),
        ], 'automation', $a->id);
        $a->send_id = $created['id'];
        $a->save();

        return true;
    }

    private function resync(WhatsAppAutomation $a, Event $event, WhatsAppSend $send, ?int $user_id, bool $byHost): void
    {
        $at = $this->runAt($a, $event);
        if (!$at) {
            return;
        }
        $settings = MessagingContext::settings($event->id);
        $guard = QuietHoursGuard::forSettings($settings);
        if (!$guard->allowed($at)) {
            $at = $guard->nextAllowed($at);
        }
        $changed = !$send->scheduled_at || !$send->scheduled_at->eq($at) || ($byHost && $send->segment != $a->segment);
        if (!$changed) {
            return;
        }
        if ($at->lt(now())) {
            if ($byHost) {
                throw new MessagingHttpException('This timing is already in the past for the event date', 422);
            }

            return;
        }
        $send->scheduled_at = $at;
        if ($byHost) {
            $send->segment = $a->segment ?? [];
            $send->version = $send->version + 1;
        }
        $send->save();
        (new SendService())->audit($send, $user_id, 'automation_resync', ['scheduled_at' => $at->toIso8601String()]);
    }

    private function cancelOccurrence(WhatsAppSend $send, ?int $user_id): void
    {
        $released = WhatsAppSendRecipient::where('send_id', $send->id)->where('status', 'pending')->update(['status' => 'cancelled', 'skip_reason' => 'automation_off']);
        $locked = WhatsAppSendRecipient::where('send_id', $send->id)->where('locked', true)->count();
        $send->status = $locked ? 'partial' : 'cancelled';
        $send->cancelled_at = now();
        $send->version = $send->version + 1;
        $send->save();
        (new SendService())->audit($send, $user_id, 'cancel', ['reason' => 'automation_off', 'released_credits' => $released], $released, $locked);
    }

    public function ensureDefaults(int $event_id): void
    {
        $have = WhatsAppAutomation::where('event_id', $event_id)->pluck('key')->all();
        foreach (TemplateCatalog::automations() as $d) {
            if (!in_array($d['key'], $have, true)) {
                WhatsAppAutomation::create($d + ['event_id' => $event_id, 'enabled' => false]);
            }
        }
    }

    private function format(WhatsAppAutomation $a, Event $event): array
    {
        $send = $a->send_id ? WhatsAppSend::find($a->send_id) : null;
        $at = $this->runAt($a, $event);
        $tpl = TemplateCatalog::get($a->template_key);

        return [
            'id' => $a->id, 'key' => $a->key, 'template_key' => $a->template_key, 'title' => $a->title, 'enabled' => (bool) $a->enabled,
            'offset_days' => (int) $a->offset_days, 'send_time' => $a->send_time, 'segment' => $a->segment ?? new \stdClass(),
            'category' => $tpl['category'] ?? 'marketing',
            'next_run_at' => $send?->scheduled_at?->toIso8601String() ?? $at?->toIso8601String(),
            'state' => !$event->starts_at ? 'needs_event_date' : ($send ? $send->status : ($at && $at->lt(now()) ? 'missed' : ($a->enabled ? 'pending' : 'off'))),
            'send_id' => $send?->id, 'send_version' => $send?->version,
        ];
    }
}
