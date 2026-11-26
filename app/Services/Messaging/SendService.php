<?php

namespace App\Services\Messaging;

use App\Models\Event;
use App\Models\EventGuest;
use App\Models\WhatsAppAuditLog;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Editable send queue (PLAN §7.1, t133u): every send stays editable until each recipient is sent.
 * - Optimistic lock: every PATCH carries expected_version; mismatch → 409 with the current state.
 * - Edits on a partly-sent send apply to pending recipients only; sent rows are locked.
 * - Credits are reserved by pending rows and released on cancel / skip.
 */
class SendService
{
    public function __construct(
        private ?TemplateService $templates = null,
        private ?QuotaService $quota = null,
        private ?AudienceResolver $audience = null,
    ) {
        $this->templates ??= new TemplateService();
        $this->quota ??= new QuotaService();
        $this->audience ??= new AudienceResolver();
    }

    // ───────────────────────── read ─────────────────────────

    public function queue(int $event_id, int $user_id, ?string $filter = null): array
    {
        MessagingContext::event($event_id, $user_id);
        $q = WhatsAppSend::where('event_id', $event_id);
        if ($filter === 'upcoming') {
            $q->whereIn('status', WhatsAppSend::EDITABLE);
        } elseif ($filter === 'history') {
            $q->whereNotIn('status', WhatsAppSend::EDITABLE);
        }
        $sends = $q->orderByRaw("CASE WHEN status IN ('scheduled','paused','sending','review') THEN 0 ELSE 1 END")
            ->orderBy('scheduled_at')->orderByDesc('id')->limit(300)->get();
        $counts = $this->counts($sends->pluck('id')->all());

        return ['sends' => $sends->map(fn ($s) => $this->format($s, $counts[$s->id] ?? []))->values()];
    }

    public function show(int $event_id, int $send_id, int $user_id): array
    {
        MessagingContext::event($event_id, $user_id);
        $send = $this->find($event_id, $send_id);

        return $this->format($send, $this->counts([$send->id])[$send->id] ?? [], true);
    }

    public function recipients(int $event_id, int $send_id, int $user_id, ?string $status = null): array
    {
        MessagingContext::event($event_id, $user_id);
        $send = $this->find($event_id, $send_id);
        $q = WhatsAppSendRecipient::where('send_id', $send->id);
        if ($status) {
            $q->whereIn('status', explode(',', $status));
        }
        $rows = $q->orderBy('id')->limit(5000)->get();
        $guests = EventGuest::whereIn('id', $rows->pluck('guest_id'))->get()->keyBy('id');

        return ['recipients' => $rows->map(fn ($r) => [
            'id' => $r->id, 'guest_id' => $r->guest_id, 'full_name' => $guests[$r->guest_id]->full_name ?? '—',
            'guest_status' => isset($guests[$r->guest_id]) ? (int) $guests[$r->guest_id]->status : null,
            'status' => $r->status, 'channel' => $r->channel, 'locked' => (bool) $r->locked, 'skip_reason' => $r->skip_reason,
            'error' => $r->error, 'sent_at' => optional($r->sent_at)->toIso8601String(),
            'delivered_at' => optional($r->delivered_at)->toIso8601String(), 'read_at' => optional($r->read_at)->toIso8601String(),
            'replied_at' => optional($r->replied_at)->toIso8601String(),
        ])->values()];
    }

    public function auditLog(int $event_id, int $send_id, int $user_id): array
    {
        MessagingContext::event($event_id, $user_id);
        $send = $this->find($event_id, $send_id);

        return ['audit' => WhatsAppAuditLog::where('send_id', $send->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($a) => ['id' => $a->id, 'action' => $a->action, 'changes' => $a->changes, 'affected_pending' => $a->affected_pending,
                'locked_unchanged' => $a->locked_unchanged, 'user_id' => $a->user_id, 'created_at' => optional($a->created_at)->toIso8601String()])->values()];
    }

    public function guestPending(int $event_id, int $guest_id, int $user_id): array
    {
        MessagingContext::event($event_id, $user_id);
        $rows = WhatsAppSendRecipient::where('event_id', $event_id)->where('guest_id', $guest_id)->orderByDesc('id')->limit(50)->get();
        $sends = WhatsAppSend::whereIn('id', $rows->pluck('send_id'))->get()->keyBy('id');

        return ['items' => $rows->map(function ($r) use ($sends) {
            $s = $sends[$r->send_id] ?? null;

            return [
                'recipient_id' => $r->id, 'send_id' => $r->send_id, 'title' => $s?->title, 'send_status' => $s?->status,
                'version' => $s?->version, 'scheduled_at' => optional($s?->scheduled_at)->toIso8601String(),
                'status' => $r->status, 'locked' => (bool) $r->locked, 'sent_at' => optional($r->sent_at)->toIso8601String(),
            ];
        })->values()];
    }

    // ───────────────────────── preview / create ─────────────────────────

    public function preview(int $event_id, int $user_id, array $data, ?int $send_id = null): array
    {
        $event = MessagingContext::event($event_id, $user_id);
        $settings = MessagingContext::settings($event_id);
        $content = TemplateService::cleanContent($data, $data['template_key'] ?? null ? TemplateCatalog::get($data['template_key']) : null);
        $segment = AudienceResolver::normalize($data['segment'] ?? []);
        $aud = $this->audience->resolve($event_id, $segment);
        $split = $this->quota->filter($event, $settings, $aud['eligible'], $send_id);
        $resolver = new VariableResolver($event, $settings);
        $sampleGuest = $aud['eligible'] ? EventGuest::find($aud['eligible'][0]) : null;
        $vars = $sampleGuest ? $resolver->forGuest($sampleGuest) : $resolver->sample();
        $at = $this->parseWhen($data['scheduled_at'] ?? null);
        $guard = QuietHoursGuard::forSettings($settings);
        $conflicts = $guard->conflicts($at);
        $category = ($data['category'] ?? TemplateCatalog::get((string) ($data['template_key'] ?? ''))['category'] ?? 'marketing') === 'utility' ? 'utility' : 'marketing';

        return [
            'audience' => [
                'matched' => $aud['matched'], 'eligible' => count($aud['eligible']), 'no_phone' => $aud['no_phone'], 'opted_out' => $aud['opted_out'],
                'will_send' => count($split['allowed']), 'over_quota' => count($split['over']), 'guest_cap' => count($split['guest_cap']),
            ],
            'rendered' => [
                'header' => VariableResolver::render($content['header'], $vars),
                'body' => VariableResolver::render($content['body'], $vars),
                'footer' => VariableResolver::render($content['footer'], $vars),
                'buttons' => $content['buttons'],
                'sample_guest' => $sampleGuest ? ['id' => $sampleGuest->id, 'full_name' => $sampleGuest->full_name] : null,
            ],
            'missing_variables' => $resolver->missing([$content['header'], $content['body']]),
            'needs_review' => $this->templates->systemMatch($data['template_key'] ?? null, $content) === null,
            'schedule' => [
                'at' => $at->toIso8601String(),
                'conflicts' => $conflicts,
                'next_allowed' => $conflicts ? $guard->nextAllowed($at)->toIso8601String() : null,
            ],
            'cost' => $this->estimate($category, count($split['allowed'])),
        ];
    }

    public function create(int $event_id, int $user_id, array $data, string $source = 'manual', ?int $automation_id = null): array
    {
        $event = MessagingContext::event($event_id, $user_id);

        return $this->createFor($event, $user_id, $data, $source, $automation_id);
    }

    /** Internal create (also used by automations, which have already checked access). */
    public function createFor(Event $event, ?int $user_id, array $data, string $source = 'manual', ?int $automation_id = null): array
    {
        $settings = MessagingContext::settings($event->id);
        $templateKey = $data['template_key'] ?? null;
        $base = $templateKey ? TemplateCatalog::get($templateKey) : null;
        if ($templateKey && !$base) {
            throw new MessagingHttpException('Unknown template', 422);
        }
        $content = TemplateService::cleanContent($data, $base);
        $category = (($data['category'] ?? $base['category'] ?? 'marketing') === 'utility') ? 'utility' : 'marketing';
        $title = trim((string) ($data['title'] ?? $base['title'] ?? 'הודעה')) ?: 'הודעה';
        $segment = AudienceResolver::normalize($data['segment'] ?? []);
        $override = (bool) ($data['override_quiet'] ?? false);
        $sendNow = (bool) ($data['send_now'] ?? false);

        $at = $sendNow ? now() : $this->parseWhen($data['scheduled_at'] ?? null);
        $guard = QuietHoursGuard::forSettings($settings);
        $conflicts = $override ? [] : $guard->conflicts($at);
        $adjustedFrom = null;
        if ($conflicts) {
            if ($sendNow) {
                throw new MessagingHttpException('Quiet hours / Shabbat are active. Confirm override to send now.', 422, ['conflicts' => $conflicts, 'next_allowed' => $guard->nextAllowed($at)->toIso8601String()]);
            }
            $adjustedFrom = $at->toIso8601String();
            $at = $guard->nextAllowed($at);
        }

        $template = $this->templates->resolveForContent($event->id, $templateKey, $title, $category, $content);
        $aud = $this->audience->resolve($event->id, $segment);
        if (!$aud['eligible'] && $source === 'manual') {
            throw new MessagingHttpException('No guests with a phone number match this audience', 422, ['audience' => $aud]);
        }

        $send = DB::transaction(function () use ($event, $user_id, $source, $automation_id, $template, $templateKey, $title, $category, $content, $segment, $at, $override, $settings, $aud) {
            $send = WhatsAppSend::create([
                'event_id' => $event->id, 'user_id' => $user_id, 'source' => $source, 'automation_id' => $automation_id,
                'template_id' => $template->id, 'template_key' => $templateKey, 'title' => mb_substr($title, 0, 160), 'category' => $category,
                'header' => $content['header'], 'body' => $content['body'], 'footer' => $content['footer'], 'buttons' => $content['buttons'],
                'segment' => $segment, 'audience_mode' => $source === 'automation' ? 'dynamic' : 'fixed',
                'status' => 'scheduled', 'scheduled_at' => $at, 'override_quiet' => $override,
            ]);
            $split = $this->quota->filter($event, $settings, $aud['eligible']);
            $this->insertRecipients($send, $split);
            $this->audit($send, $user_id, 'create', ['scheduled_at' => $send->scheduled_at->toIso8601String(), 'recipients' => count($split['allowed'])], count($split['allowed']));

            return $send;
        });
        $send->refresh();

        if ($sendNow) {
            (new Dispatcher())->processSend($send->id);
            $send->refresh();
        }

        $out = $this->format($send, $this->counts([$send->id])[$send->id] ?? [], true);
        $out['adjusted_from'] = $adjustedFrom;
        $out['audience_report'] = ['no_phone' => $aud['no_phone'], 'opted_out' => $aud['opted_out']];

        return $out;
    }

    // ───────────────────────── edit (optimistic lock) ─────────────────────────

    public function update(int $event_id, int $send_id, int $user_id, array $data): array
    {
        $event = MessagingContext::event($event_id, $user_id);
        $settings = MessagingContext::settings($event_id);
        $action = (string) ($data['action'] ?? 'update');
        if (!in_array($action, ['update', 'pause', 'resume', 'cancel', 'send_now'], true)) {
            throw new MessagingHttpException('Unknown action', 422);
        }
        if (!array_key_exists('expected_version', $data)) {
            throw new MessagingHttpException('expected_version is required', 422);
        }

        $result = DB::transaction(function () use ($event, $settings, $send_id, $user_id, $data, $action) {
            $send = WhatsAppSend::where('event_id', $event->id)->where('id', $send_id)->lockForUpdate()->first();
            if (!$send) {
                throw new MessagingHttpException('Send not found', 404);
            }
            if ((int) $data['expected_version'] !== (int) $send->version) {
                throw new MessagingHttpException('This message was changed in the meantime. Reload and try again.', 409, [
                    'conflict' => true, 'send' => $this->format($send, $this->counts([$send->id])[$send->id] ?? [], true),
                ]);
            }
            if (!$send->isEditable()) {
                throw new MessagingHttpException('This message was already sent or cancelled and can no longer be changed', 422, ['status' => $send->status]);
            }

            $pending = fn () => WhatsAppSendRecipient::where('send_id', $send->id)->where('status', 'pending')->count();
            $locked = WhatsAppSendRecipient::where('send_id', $send->id)->where('locked', true)->count();
            $changes = [];
            $dispatchNow = false;

            switch ($action) {
                case 'pause':
                    $send->status = 'paused';
                    $send->paused_at = now();
                    $changes['status'] = 'paused';
                    break;
                case 'resume':
                    $send->status = $send->started_at ? 'sending' : 'scheduled';
                    $send->paused_at = null;
                    $changes['status'] = $send->status;
                    break;
                case 'cancel':
                    $released = WhatsAppSendRecipient::where('send_id', $send->id)->where('status', 'pending')
                        ->update(['status' => 'cancelled', 'skip_reason' => 'cancelled', 'updated_at' => now()]);
                    $send->status = $locked > 0 ? 'partial' : 'cancelled';
                    $send->cancelled_at = now();
                    $send->completed_at = now();
                    $changes = ['status' => 'cancelled', 'released_credits' => $released];
                    break;
                case 'send_now':
                    $override = (bool) ($data['override_quiet'] ?? false);
                    $guard = QuietHoursGuard::forSettings($settings);
                    $conf = ($override || $send->override_quiet) ? [] : $guard->conflicts(now());
                    if ($conf) {
                        throw new MessagingHttpException('Quiet hours / Shabbat are active. Confirm override to send now.', 422, ['conflicts' => $conf, 'next_allowed' => $guard->nextAllowed(now())->toIso8601String()]);
                    }
                    $send->scheduled_at = now();
                    $send->override_quiet = $send->override_quiet || $override;
                    if ($send->status === 'paused') {
                        $send->status = $send->started_at ? 'sending' : 'scheduled';
                        $send->paused_at = null;
                    }
                    $changes = ['scheduled_at' => 'now', 'override_quiet' => $send->override_quiet];
                    $dispatchNow = true;
                    break;
                default:
                    $changes = $this->applyUpdate($event, $settings, $send, $data);
            }

            $send->version = $send->version + 1;
            $send->save();
            $affected = $action === 'cancel' ? ($changes['released_credits'] ?? 0) : $pending();
            $this->audit($send, $user_id, $action, $changes, $affected, $locked);

            return ['send' => $send, 'changes' => $changes, 'pending' => $affected, 'locked' => $locked, 'dispatch' => $dispatchNow];
        });

        if ($result['dispatch']) {
            (new Dispatcher())->processSend($result['send']->id);
            $result['send']->refresh();
        }
        $send = $result['send'];
        $out = $this->format($send, $this->counts([$send->id])[$send->id] ?? [], true);
        $out['pending_updated'] = $result['pending'];
        $out['locked_unchanged'] = $result['locked'];
        $out['changes'] = $result['changes'];

        return $out;
    }

    private function applyUpdate(Event $event, $settings, WhatsAppSend $send, array $data): array
    {
        $changes = [];
        if (array_key_exists('title', $data) && trim((string) $data['title']) !== '') {
            $send->title = mb_substr(trim((string) $data['title']), 0, 160);
            $changes['title'] = $send->title;
        }

        $contentKeys = array_intersect(array_keys($data), ['header', 'body', 'footer', 'buttons', 'category']);
        if ($contentKeys) {
            $current = ['header' => $send->header, 'body' => $send->body, 'footer' => $send->footer, 'buttons' => $send->buttons ?? []];
            $content = TemplateService::cleanContent($data, $current);
            $category = array_key_exists('category', $data) ? ($data['category'] === 'utility' ? 'utility' : 'marketing') : $send->category;
            if (!TemplateService::sameContent($current, $content) || $category !== $send->category) {
                $tpl = $this->templates->resolveForContent($event->id, $send->template_key, $send->title, $category, $content);
                $send->fill($content);
                $send->category = $category;
                $send->template_id = $tpl->id;
                $send->content_version = $send->content_version + 1;
                $changes['content'] = ['content_version' => $send->content_version, 'needs_review' => $tpl->meta_status !== 'approved', 'template_status' => $tpl->meta_status];
                if ($send->status === 'review' && $tpl->meta_status === 'approved') {
                    $send->status = $send->started_at ? 'sending' : 'scheduled';
                }
            }
        }

        if (array_key_exists('override_quiet', $data)) {
            $send->override_quiet = (bool) $data['override_quiet'];
            $changes['override_quiet'] = $send->override_quiet;
        }

        if (array_key_exists('scheduled_at', $data)) {
            $at = $this->parseWhen($data['scheduled_at']);
            $guard = QuietHoursGuard::forSettings($settings);
            $conf = $send->override_quiet ? [] : $guard->conflicts($at);
            if ($conf) {
                $changes['adjusted_from'] = $at->toIso8601String();
                $changes['conflicts'] = $conf;
                $at = $guard->nextAllowed($at);
            }
            $send->scheduled_at = $at;
            $changes['scheduled_at'] = $at->toIso8601String();
        }

        if (array_key_exists('segment', $data)) {
            $segment = AudienceResolver::normalize((array) $data['segment']);
            $aud = $this->audience->resolve($event->id, $segment);
            $want = array_flip($aud['eligible']);
            $rows = WhatsAppSendRecipient::where('send_id', $send->id)->get()->keyBy('guest_id');
            $removed = 0;
            foreach ($rows as $gid => $r) {
                if ($r->status === 'pending' && !isset($want[$gid])) {
                    $r->update(['status' => 'cancelled', 'skip_reason' => 'audience_changed']);
                    $removed++;
                }
            }
            $candidates = [];
            foreach ($aud['eligible'] as $gid) {
                $r = $rows[$gid] ?? null;
                if (!$r || ($r->status === 'cancelled' && $r->skip_reason === 'audience_changed') || ($r->status === 'skipped' && in_array($r->skip_reason, ['quota', 'guest_cap'], true))) {
                    $candidates[] = $gid;
                }
            }
            $split = $this->quota->filter($event, $settings, $candidates);
            $added = $this->insertRecipients($send, $split, $rows->all());
            $send->segment = $segment;
            $send->audience_mode = 'fixed';
            $changes['segment'] = ['added' => $added, 'removed' => $removed, 'over_quota' => count($split['over']) + count($split['guest_cap'])];
        }

        if (!$changes) {
            throw new MessagingHttpException('Nothing to change', 422);
        }

        return $changes;
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @return int number of pending rows created / revived */
    public function insertRecipients(WhatsAppSend $send, array $split, array $existing = []): int
    {
        $now = now();
        $insert = [];
        $added = 0;
        $put = function (int $gid, string $status, ?string $reason) use ($send, $existing, $now, &$insert, &$added) {
            if (isset($existing[$gid])) {
                $existing[$gid]->update(['status' => $status, 'skip_reason' => $reason, 'error' => null]);
            } else {
                $insert[] = ['send_id' => $send->id, 'event_id' => $send->event_id, 'guest_id' => $gid, 'status' => $status, 'skip_reason' => $reason, 'created_at' => $now, 'updated_at' => $now];
            }
            if ($status === 'pending') {
                $added++;
            }
        };
        foreach ($split['allowed'] as $gid) {
            $put($gid, 'pending', null);
        }
        foreach ($split['over'] as $gid) {
            $put($gid, 'skipped', 'quota');
        }
        foreach ($split['guest_cap'] as $gid) {
            $put($gid, 'skipped', 'guest_cap');
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            WhatsAppSendRecipient::insert($chunk);
        }

        return $added;
    }

    public function audit(WhatsAppSend $send, ?int $user_id, string $action, array $changes = [], int $affected = 0, int $locked = 0, ?int $guest_id = null): void
    {
        WhatsAppAuditLog::create([
            'event_id' => $send->event_id, 'send_id' => $send->id, 'guest_id' => $guest_id, 'user_id' => $user_id,
            'action' => $action, 'changes' => $changes, 'affected_pending' => $affected, 'locked_unchanged' => $locked,
        ]);
    }

    public function counts(array $send_ids): array
    {
        if (!$send_ids) {
            return [];
        }
        $rows = WhatsAppSendRecipient::whereIn('send_id', $send_ids)
            ->select('send_id', 'status', DB::raw('COUNT(*) as c'), DB::raw('SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied'), DB::raw("SUM(CASE WHEN channel = 'sms' THEN 1 ELSE 0 END) as sms"))
            ->groupBy('send_id', 'status')->get();
        $out = [];
        foreach ($rows as $r) {
            $o = &$out[$r->send_id];
            $o[$r->status] = (int) $r->c;
            $o['replied'] = ($o['replied'] ?? 0) + (int) $r->replied;
            $o['sms'] = ($o['sms'] ?? 0) + (int) $r->sms;
            unset($o);
        }

        return $out;
    }

    public function format(WhatsAppSend $s, array $c = [], bool $full = false): array
    {
        $g = fn ($k) => (int) ($c[$k] ?? 0);
        $sent = $g('sent') + $g('delivered') + $g('read');
        $template = $s->template_id ? WhatsAppTemplate::find($s->template_id) : null;
        $out = [
            'id' => $s->id, 'title' => $s->title, 'source' => $s->source, 'automation_id' => $s->automation_id,
            'template_id' => $s->template_id, 'template_key' => $s->template_key, 'category' => $s->category, 'status' => $s->status,
            'scheduled_at' => optional($s->scheduled_at)->toIso8601String(), 'override_quiet' => (bool) $s->override_quiet,
            'version' => (int) $s->version, 'content_version' => (int) $s->content_version, 'audience_mode' => $s->audience_mode,
            'editable' => $s->isEditable(),
            'template_status' => $template?->meta_status, 'template_reason' => $template?->meta_reason,
            'counts' => [
                'pending' => $g('pending') + $g('sending'), 'sent' => $sent, 'delivered' => $g('delivered') + $g('read'), 'read' => $g('read'),
                'replied' => $g('replied'), 'failed' => $g('failed'), 'skipped' => $g('skipped'), 'cancelled' => $g('cancelled'), 'sms' => $g('sms'),
                'total' => array_sum(array_map(fn ($k) => $g($k), ['pending', 'sending', 'sent', 'delivered', 'read', 'failed', 'skipped', 'cancelled'])),
            ],
            'started_at' => optional($s->started_at)->toIso8601String(), 'completed_at' => optional($s->completed_at)->toIso8601String(),
            'created_at' => optional($s->created_at)->toIso8601String(),
            'header' => $s->header, 'body' => $s->body, 'footer' => $s->footer, 'buttons' => $s->buttons ?? [], 'segment' => $s->segment ?? new \stdClass(),
        ];
        if ($full) {
            $event = Event::find($s->event_id);
            $vars = (new VariableResolver($event, MessagingContext::settings($s->event_id)))->sample();
            $out['rendered'] = [
                'header' => VariableResolver::render($s->header, $vars), 'body' => VariableResolver::render($s->body, $vars), 'footer' => VariableResolver::render($s->footer, $vars),
            ];
            $out['cost'] = $this->estimate($s->category, $out['counts']['pending'] + $sent);
        }

        return $out;
    }

    public function estimate(string $category, int $messages): array
    {
        $usd = (float) (config('whatsapp_messaging.rates_usd')[$category] ?? 0) * $messages;

        return ['messages' => $messages, 'usd' => round($usd, 2), 'ils' => round($usd * (float) config('whatsapp_messaging.usd_to_ils', 3.7), 2), 'category' => $category];
    }

    public function find(int $event_id, int $send_id): WhatsAppSend
    {
        $send = WhatsAppSend::where('event_id', $event_id)->where('id', $send_id)->first();
        if (!$send) {
            throw new MessagingHttpException('Send not found', 404);
        }

        return $send;
    }

    public function parseWhen($value): Carbon
    {
        if ($value === null || $value === '' || $value === 'now') {
            return now()->utc();
        }
        try {
            // A value without an offset is local Israel time (what the host typed).
            $s = (string) $value;
            $hasTz = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s);
            $at = $hasTz ? Carbon::parse($s) : Carbon::parse($s, config('whatsapp_messaging.timezone', 'Asia/Jerusalem'));
        } catch (\Throwable) {
            throw new MessagingHttpException('Invalid schedule time', 422);
        }
        $at = $at->utc();
        if ($at->lt(now()->subMinutes(2))) {
            throw new MessagingHttpException('The schedule time is in the past', 422);
        }
        if ($at->gt(now()->addYears(2))) {
            throw new MessagingHttpException('The schedule time is too far in the future', 422);
        }

        return $at->lt(now()) ? now()->utc() : $at;
    }
}
