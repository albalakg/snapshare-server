<?php

namespace App\Services\Messaging;

use App\Models\EventGuest;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use App\Services\Guests\GuestCrmService;
use App\Services\Messaging\Providers\ProviderFactory;
use Illuminate\Support\Facades\DB;

/** Overview, settings, analytics, inbox, test send and mock-only dev helpers. */
class MessagingService
{
    public function __construct(private ?QuotaService $quota = null, private ?SendService $sends = null)
    {
        $this->quota ??= new QuotaService();
        $this->sends ??= new SendService();
    }

    public function overview(int $event_id, int $user_id): array
    {
        $event = MessagingContext::event($event_id, $user_id);
        $settings = MessagingContext::settings($event_id);
        $guests = EventGuest::where('event_id', $event_id)->get(['id', 'status', 'phone_hash', 'wa_opted_out_at', 'party_size', 'rsvp_source']);
        $withPhone = $guests->filter(fn ($g) => $g->phone_hash && !$g->wa_opted_out_at)->count();
        $byStatus = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $people = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($guests as $g) {
            $byStatus[(int) $g->status] = ($byStatus[(int) $g->status] ?? 0) + 1;
            $people[(int) $g->status] = ($people[(int) $g->status] ?? 0) + max(1, (int) $g->party_size);
        }
        $next = WhatsAppSend::where('event_id', $event_id)->whereIn('status', ['scheduled', 'review', 'sending'])->orderBy('scheduled_at')->first();
        $vars = (new VariableResolver($event, $settings))->eventVars();

        return [
            'enabled' => true,
            'provider' => ProviderFactory::whatsappName(),
            'sms_provider' => ProviderFactory::sms()->name(),
            'dev_tools' => $this->devAllowed(),
            'event' => ['id' => $event->id, 'name' => $event->name, 'starts_at' => $event->starts_at ? \Carbon\Carbon::parse($event->starts_at, 'UTC')->toIso8601String() : null, 'vars' => $vars],
            'quota' => $this->quota->summary($event, $settings, $withPhone),
            'guests' => [
                'total' => $guests->count(), 'with_phone' => $guests->filter(fn ($g) => $g->phone_hash)->count(),
                'no_phone' => $guests->filter(fn ($g) => !$g->phone_hash)->count(), 'opted_out' => $guests->filter(fn ($g) => $g->wa_opted_out_at)->count(),
                'by_status' => $byStatus, 'people_by_status' => $people,
                'responded_whatsapp' => $guests->where('rsvp_source', 'whatsapp')->count(), 'responded_web' => $guests->where('rsvp_source', 'web')->count(),
            ],
            'queue' => [
                'upcoming' => WhatsAppSend::where('event_id', $event_id)->whereIn('status', WhatsAppSend::EDITABLE)->count(),
                'pending_messages' => WhatsAppSendRecipient::where('event_id', $event_id)->where('status', 'pending')->count(),
                'review' => WhatsAppSend::where('event_id', $event_id)->where('status', 'review')->count(),
                'next' => $next ? $this->sends->format($next, $this->sends->counts([$next->id])[$next->id] ?? []) : null,
            ],
            'inbox' => [
                'unread' => WhatsAppMessage::where('event_id', $event_id)->where('direction', 'in')->whereNull('read_by_host_at')->count(),
                'suggestions' => WhatsAppMessage::where('event_id', $event_id)->where('direction', 'in')->whereNotNull('suggestion')->whereNull('handled')->count(),
            ],
            'funnel' => $this->funnel($event_id),
            'settings' => $this->formatSettings($settings),
        ];
    }

    public function funnel(int $event_id): array
    {
        $row = WhatsAppSendRecipient::where('event_id', $event_id)->select(
            DB::raw("SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) as sent"),
            DB::raw("SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) as delivered"),
            DB::raw("SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as `read`"),
            DB::raw('SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied'),
            DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"),
            DB::raw("SUM(CASE WHEN channel = 'sms' THEN 1 ELSE 0 END) as sms"),
        )->first();

        return ['sent' => (int) $row->sent, 'delivered' => (int) $row->delivered, 'read' => (int) $row->read, 'replied' => (int) $row->replied, 'failed' => (int) $row->failed, 'sms' => (int) $row->sms];
    }

    public function analytics(int $event_id, int $user_id): array
    {
        MessagingContext::event($event_id, $user_id);
        $sends = WhatsAppSend::where('event_id', $event_id)->whereNotIn('status', ['scheduled', 'review', 'paused'])->orderByDesc('started_at')->limit(100)->get();
        $counts = $this->sends->counts($sends->pluck('id')->all());
        $rates = config('whatsapp_messaging.rates_usd');
        $usd = 0.0;
        $rows = $sends->map(function ($s) use ($counts, $rates, &$usd) {
            $f = $this->sends->format($s, $counts[$s->id] ?? []);
            $usd += ($rates[$s->category] ?? 0) * $f['counts']['sent'];
            unset($f['body'], $f['header'], $f['footer'], $f['buttons']);

            return $f;
        })->values();
        $byIntent = WhatsAppMessage::where('event_id', $event_id)->where('direction', 'in')->select('intent', DB::raw('COUNT(*) as c'))->groupBy('intent')->pluck('c', 'intent');

        return [
            'funnel' => $this->funnel($event_id),
            'sends' => $rows,
            'replies_by_intent' => $byIntent,
            'cost' => ['usd' => round($usd, 2), 'ils' => round($usd * (float) config('whatsapp_messaging.usd_to_ils', 3.7), 2)],
        ];
    }

    /** Guests for the audience picker: phone / opt-out state, quota usage, pending queue per guest. */
    public function audience(int $event_id, int $user_id): array
    {
        $event = MessagingContext::event($event_id, $user_id);
        $settings = MessagingContext::settings($event_id);
        $limit = $this->quota->perGuest($event, $settings);
        $usage = $this->quota->usage($event_id);
        $guests = EventGuest::where('event_id', $event_id)->orderBy('id')->get();
        $tables = \Illuminate\Support\Facades\DB::table('seating_assignments as a')
            ->join('seating_tables as t', 't.id', '=', 'a.table_id')->join('seating_plans as p', 'p.id', '=', 'a.plan_id')
            ->where('p.event_id', $event_id)->pluck('t.label', 'a.guest_id');
        $last = WhatsAppSendRecipient::where('event_id', $event_id)->whereIn('status', ['sent', 'delivered', 'read', 'failed'])
            ->orderBy('id')->get(['guest_id', 'status', 'replied_at'])->keyBy('guest_id');
        $groups = \App\Models\EventGuestGroup::where('event_id', $event_id)->orderBy('name')->get(['id', 'name', 'side']);

        return [
            'per_guest' => $limit,
            'groups' => $groups,
            'guests' => $guests->map(function ($g) use ($usage, $tables, $last, $limit) {
                $u = $usage[$g->id] ?? ['used' => 0, 'reserved' => 0];
                $l = $last[$g->id] ?? null;

                return [
                    'id' => $g->id, 'full_name' => $g->full_name, 'status' => (int) $g->status, 'side' => $g->side, 'group_id' => $g->group_id ? (int) $g->group_id : null,
                    'party_size' => (int) $g->party_size, 'has_phone' => (bool) $g->phone_hash, 'opted_out' => (bool) $g->wa_opted_out_at,
                    'table_label' => isset($tables[$g->id]) ? (string) $tables[$g->id] : null,
                    'used' => $u['used'], 'pending' => $u['reserved'], 'left' => max(0, $limit - $u['used'] - $u['reserved']),
                    'last_status' => $l?->replied_at ? 'replied' : $l?->status, 'rsvp_source' => $g->rsvp_source,
                    'answers' => (array) (($g->metadata['rsvp'] ?? null) ?: new \stdClass()),
                ];
            })->values(),
        ];
    }

    // ───────────────────────── settings ─────────────────────────

    public function settings(int $event_id, int $user_id): array
    {
        MessagingContext::event($event_id, $user_id);

        return $this->formatSettings(MessagingContext::settings($event_id));
    }

    public function updateSettings(int $event_id, int $user_id, array $data): array
    {
        MessagingContext::event($event_id, $user_id);
        $s = MessagingContext::settings($event_id);
        foreach (['quiet_hours_enabled', 'shabbat_guard_enabled', 'sms_fallback_enabled'] as $k) {
            if (array_key_exists($k, $data)) {
                $s->{$k} = (bool) $data[$k];
            }
        }
        if (array_key_exists('variables', $data)) {
            $vars = [];
            foreach (['couple_names', 'event_title', 'event_time', 'venue_name', 'venue_address', 'waze_link', 'gift_link', 'hub_link'] as $k) {
                $v = trim(mb_substr((string) (($data['variables'][$k] ?? '')), 0, 300));
                if ($v !== '') {
                    if (str_ends_with($k, '_link') && !preg_match('#^https://#i', $v)) {
                        throw new MessagingHttpException('Links must start with https://', 422, ['field' => $k]);
                    }
                    $vars[$k] = $v;
                }
            }
            $s->variables = $vars;
        }
        if (array_key_exists('rsvp_config', $data)) {
            $in = (array) $data['rsvp_config'];
            $cur = MessagingContext::rsvpConfig($s);
            foreach (['ask_party_size', 'ask_meal', 'ask_allergies', 'ask_shuttle'] as $k) {
                if (array_key_exists($k, $in)) {
                    $cur[$k] = (bool) $in[$k];
                }
            }
            if (array_key_exists('max_party_size', $in)) {
                $cur['max_party_size'] = max(1, min(30, (int) $in['max_party_size']));
            }
            if (array_key_exists('meals', $in)) {
                $cur['meals'] = array_values(array_slice(array_filter(array_map(fn ($m) => trim(mb_substr((string) $m, 0, 40)), (array) $in['meals'])), 0, 8));
            }
            foreach (['thank_you_yes', 'thank_you_no', 'thank_you_maybe'] as $k) {
                if (array_key_exists($k, $in) && trim((string) $in[$k]) !== '') {
                    $cur[$k] = trim(mb_substr((string) $in[$k], 0, 300));
                }
            }
            $s->rsvp_config = $cur;
        }
        $s->save();

        return $this->formatSettings($s);
    }

    public function formatSettings($s): array
    {
        return [
            'quiet_hours_enabled' => (bool) $s->quiet_hours_enabled, 'shabbat_guard_enabled' => (bool) $s->shabbat_guard_enabled,
            'sms_fallback_enabled' => (bool) $s->sms_fallback_enabled, 'variables' => (array) ($s->variables ?? []) ?: new \stdClass(),
            'rsvp_config' => MessagingContext::rsvpConfig($s), 'quiet_hours' => config('whatsapp_messaging.quiet_hours'), 'shabbat' => config('whatsapp_messaging.shabbat'),
            'test_sends_used' => (int) $s->test_sends_used, 'test_sends_limit' => (int) config('whatsapp_messaging.test_sends_per_event'),
        ];
    }

    // ───────────────────────── test send ─────────────────────────

    public function testSend(int $event_id, int $user_id, array $data): array
    {
        $event = MessagingContext::event($event_id, $user_id);
        $s = MessagingContext::settings($event_id);
        if ($s->test_sends_used >= (int) config('whatsapp_messaging.test_sends_per_event', 20)) {
            throw new MessagingHttpException('Test-send limit reached for this event', 429);
        }
        $crm = new GuestCrmService();
        try {
            $phone = $crm->canonicalPhone((string) ($data['phone'] ?? ''));
            $to = \App\Services\Guests\PhoneNormalizer::toWhatsApp($phone);
        } catch (\Exception) {
            throw new MessagingHttpException('Invalid phone number', 422);
        }
        $key = $data['template_key'] ?? null;
        $content = TemplateService::cleanContent($data, $key ? TemplateCatalog::get($key) : null);
        $templates = new TemplateService();
        $sys = $templates->systemMatch($key, $content);
        if (!$sys && !ProviderFactory::isMock()) {
            throw new MessagingHttpException('Edited text must be approved by Meta before a test send. Save it to submit for review.', 422, ['needs_review' => true]);
        }
        $vars = (new VariableResolver($event, $s))->sample();
        $res = ProviderFactory::whatsapp()->sendTemplate($to, [
            'meta_name' => $sys?->meta_name ?? 'mock_preview', 'language' => 'he', 'header' => $content['header'], 'body' => $content['body'], 'buttons' => $content['buttons'],
        ], $vars);
        $s->test_sends_used = $s->test_sends_used + 1;
        $s->save();
        if (!$res->ok) {
            throw new MessagingHttpException('Test send failed: ' . $res->error, 422);
        }

        return ['ok' => true, 'provider' => ProviderFactory::whatsappName(), 'id' => $res->id, 'used' => $s->test_sends_used];
    }

    // ───────────────────────── inbox ─────────────────────────

    public function inbox(int $event_id, int $user_id): array
    {
        MessagingContext::event($event_id, $user_id);
        $latest = WhatsAppMessage::where('event_id', $event_id)->whereNotNull('guest_id')
            ->select('guest_id', DB::raw('MAX(id) as last_id'), DB::raw("SUM(CASE WHEN direction = 'in' AND read_by_host_at IS NULL THEN 1 ELSE 0 END) as unread"), DB::raw("SUM(CASE WHEN direction = 'in' THEN 1 ELSE 0 END) as inbound"))
            ->groupBy('guest_id')->having('inbound', '>', 0)->orderByDesc('last_id')->limit(300)->get();
        $msgs = WhatsAppMessage::whereIn('id', $latest->pluck('last_id'))->get()->keyBy('id');
        $guests = EventGuest::whereIn('id', $latest->pluck('guest_id'))->get()->keyBy('id');
        $convs = WhatsAppConversation::where('event_id', $event_id)->whereIn('guest_id', $latest->pluck('guest_id'))->get()->keyBy('guest_id');
        $sugg = WhatsAppMessage::where('event_id', $event_id)->where('direction', 'in')->whereNotNull('suggestion')->whereNull('handled')
            ->whereIn('guest_id', $latest->pluck('guest_id'))->orderByDesc('id')->get()->unique('guest_id')->keyBy('guest_id');

        return ['threads' => $latest->map(function ($row) use ($msgs, $guests, $convs, $sugg) {
            $g = $guests[$row->guest_id] ?? null;
            $m = $msgs[$row->last_id] ?? null;
            $c = $convs[$row->guest_id] ?? null;
            $s = $sugg[$row->guest_id] ?? null;

            return [
                'guest_id' => (int) $row->guest_id, 'full_name' => $g?->full_name ?? '—', 'guest_status' => $g ? (int) $g->status : null,
                'party_size' => $g ? (int) $g->party_size : null, 'unread' => (int) $row->unread,
                'last' => $m ? ['direction' => $m->direction, 'body' => mb_substr((string) $m->body, 0, 140), 'at' => optional($m->created_at)->toIso8601String(), 'intent' => $m->intent] : null,
                'window_open' => $this->windowOpen($c), 'bot_step' => $c?->step,
                'suggestion' => $s ? ['message_id' => $s->id, 'changes' => $s->suggestion] : null,
            ];
        })->values()];
    }

    public function thread(int $event_id, int $guest_id, int $user_id): array
    {
        MessagingContext::event($event_id, $user_id);
        $guest = $this->guest($event_id, $guest_id);
        $msgs = WhatsAppMessage::where('event_id', $event_id)->where('guest_id', $guest_id)->orderBy('id')->limit(300)->get();
        WhatsAppMessage::where('event_id', $event_id)->where('guest_id', $guest_id)->where('direction', 'in')->whereNull('read_by_host_at')->update(['read_by_host_at' => now()]);
        $conv = WhatsAppConversation::where('event_id', $event_id)->where('guest_id', $guest_id)->first();

        return [
            'guest' => (new GuestCrmService())->format($guest) + ['answers' => (array) (($guest->metadata['rsvp'] ?? null) ?: new \stdClass()), 'opted_out' => (bool) $guest->wa_opted_out_at],
            'window_open' => $this->windowOpen($conv),
            'window_closes_at' => $conv?->last_inbound_at?->copy()->addHours((int) config('whatsapp_messaging.service_window_hours', 24))->toIso8601String(),
            'messages' => $msgs->map(fn ($m) => [
                'id' => $m->id, 'direction' => $m->direction, 'kind' => $m->kind, 'body' => $m->body, 'status' => $m->status, 'intent' => $m->intent,
                'suggestion' => $m->suggestion, 'handled' => $m->handled, 'at' => optional($m->created_at)->toIso8601String(),
            ])->values(),
        ];
    }

    public function reply(int $event_id, int $guest_id, int $user_id, string $body): array
    {
        MessagingContext::event($event_id, $user_id);
        $guest = $this->guest($event_id, $guest_id);
        $body = trim(mb_substr($body, 0, 1000));
        if ($body === '') {
            throw new MessagingHttpException('Message is empty', 422);
        }
        $conv = WhatsAppConversation::where('event_id', $event_id)->where('guest_id', $guest_id)->first();
        if (!$this->windowOpen($conv)) {
            throw new MessagingHttpException('The free 24h reply window is closed. Send an approved template instead.', 422, ['window_open' => false]);
        }
        if ($guest->wa_opted_out_at) {
            throw new MessagingHttpException('This guest opted out of messages', 422);
        }
        $msg = (new InboundService())->reply($guest, $body, 'host');
        if (!$msg || $msg->status !== 'sent') {
            throw new MessagingHttpException('Reply failed', 422);
        }

        return ['id' => $msg->id, 'status' => $msg->status];
    }

    public function applySuggestion(int $event_id, int $message_id, int $user_id, bool $apply, ?array $changes = null): array
    {
        MessagingContext::event($event_id, $user_id);
        $msg = WhatsAppMessage::where('event_id', $event_id)->where('id', $message_id)->where('direction', 'in')->first();
        if (!$msg || !$msg->guest_id) {
            throw new MessagingHttpException('Message not found', 404);
        }
        if ($apply) {
            $s = $changes ?? (array) $msg->suggestion;
            $answers = [];
            if (isset($s['status'])) {
                $answers['status'] = [1 => 'yes', 2 => 'no', 3 => 'maybe'][(int) $s['status']] ?? null;
            }
            foreach (['party_size', 'meal', 'allergies'] as $k) {
                if (isset($s[$k])) {
                    $answers[$k] = $s[$k];
                }
            }
            $answers = array_filter($answers, fn ($v) => $v !== null);
            if (!$answers) {
                throw new MessagingHttpException('Nothing to apply', 422);
            }
            (new RsvpService())->apply($this->guest($event_id, (int) $msg->guest_id), $answers, 'host');
        }
        $msg->update(['handled' => $apply ? 'applied' : 'dismissed']);

        return ['handled' => $msg->handled, 'guest' => (new GuestCrmService())->format($this->guest($event_id, (int) $msg->guest_id))];
    }

    // ───────────────────────── dev (mock only) ─────────────────────────

    public function devAllowed(): bool
    {
        return ProviderFactory::isMock() && (bool) config('whatsapp_messaging.mock.dev_endpoints') && !app()->environment('production');
    }

    public function assertDev(): void
    {
        if (!$this->devAllowed()) {
            throw new MessagingHttpException('Not available', 404);
        }
    }

    public function devInbound(int $event_id, int $user_id, array $data): array
    {
        MessagingContext::event($event_id, $user_id);
        $this->assertDev();
        $guest = $this->guest($event_id, (int) ($data['guest_id'] ?? 0));
        if (!$guest->phone) {
            throw new MessagingHttpException('Guest has no phone', 422);
        }
        $from = \App\Services\Guests\PhoneNormalizer::toWhatsApp($guest->phone);
        $payload = $data['payload'] ?? null;
        if ($payload && !str_contains($payload, ':')) {
            $r = WhatsAppSendRecipient::where('event_id', $event_id)->where('guest_id', $guest->id)->whereNotNull('sent_at')->orderByDesc('id')->first();
            $payload .= $r ? ':' . $r->id : '';
        }

        return (new InboundService())->handle($from, (string) ($data['text'] ?? ''), $payload, null, 'wamid.devin.' . uniqid());
    }

    public function devTemplate(int $event_id, int $user_id, int $template_id, string $status): array
    {
        MessagingContext::event($event_id, $user_id);
        $this->assertDev();
        $tpl = WhatsAppTemplate::where('id', $template_id)->where('event_id', $event_id)->first();
        if (!$tpl || !in_array($status, ['approved', 'rejected'], true)) {
            throw new MessagingHttpException('Template not found', 404);
        }

        return (new TemplateService())->format((new TemplateService())->forceStatus($tpl, $status));
    }

    public function devDelivery(int $event_id, int $user_id, int $send_id, string $status): array
    {
        MessagingContext::event($event_id, $user_id);
        $this->assertDev();
        $n = 0;
        foreach (WhatsAppSendRecipient::where('event_id', $event_id)->where('send_id', $send_id)->whereNotNull('provider_message_id')->get() as $r) {
            (new Dispatcher())->applyStatus($r->provider_message_id, $status);
            $n++;
        }

        return ['updated' => $n];
    }

    public function devEntitlement(int $event_id, int $user_id, array $data): array
    {
        MessagingContext::event($event_id, $user_id);
        $this->assertDev();
        $s = MessagingContext::settings($event_id);
        if (array_key_exists('pro_addon', $data)) {
            $s->pro_addon = (bool) $data['pro_addon'];
        }
        if (array_key_exists('topups', $data)) {
            $s->topups = max(0, min(10, (int) $data['topups']));
        }
        $s->save();

        return ['pro_addon' => $s->pro_addon, 'topups' => $s->topups];
    }

    private function windowOpen(?WhatsAppConversation $c): bool
    {
        return $c && $c->last_inbound_at && $c->last_inbound_at->gt(now()->subHours((int) config('whatsapp_messaging.service_window_hours', 24)));
    }

    private function guest(int $event_id, int $guest_id): EventGuest
    {
        $g = EventGuest::where('event_id', $event_id)->where('id', $guest_id)->first();
        if (!$g) {
            throw new MessagingHttpException('Guest not found', 404);
        }

        return $g;
    }
}
