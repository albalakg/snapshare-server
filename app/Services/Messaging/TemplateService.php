<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppTemplate;
use App\Services\Messaging\Providers\ProviderFactory;
use App\Services\Messaging\Providers\WhatsAppProviderInterface;

/**
 * D5: SnapShare ready templates (pre-approved on SnapShare's WABA) + host custom templates that go to Meta review.
 * Editing the body/header/footer/buttons of a ready template creates an event custom template (pending review).
 * Changing variable VALUES (venue, time, names) never needs re-approval.
 */
class TemplateService
{
    public function __construct(private ?WhatsAppProviderInterface $provider = null)
    {
        $this->provider ??= ProviderFactory::whatsapp();
    }

    /** Upsert system catalog rows (event_id = null). Mock: approved. Meta: draft until submitted by the artisan command. */
    public function ensureSystemTemplates(): void
    {
        $prefix = config('whatsapp_messaging.meta.template_prefix', 'snapshare');
        $existing = WhatsAppTemplate::whereNull('event_id')->get()->keyBy('key');
        foreach (TemplateCatalog::all() as $key => $t) {
            if (!empty($t['custom']) || isset($existing[$key])) {
                continue;
            }
            WhatsAppTemplate::create([
                'event_id' => null, 'key' => $key, 'base_key' => $key, 'title' => $t['title'], 'stage' => $t['stage'],
                'category' => $t['category'], 'header' => $t['header'], 'body' => $t['body'], 'footer' => $t['footer'],
                'buttons' => $t['buttons'], 'meta_name' => $prefix . '_' . $key . '_v1', 'is_system' => true,
                'meta_status' => ProviderFactory::isMock() ? 'approved' : 'draft',
            ]);
        }
    }

    public function list(int $event_id): array
    {
        $this->ensureSystemTemplates();
        $this->syncPending($event_id);
        $system = WhatsAppTemplate::whereNull('event_id')->get()->keyBy('key');
        $out = [];
        foreach (TemplateCatalog::all() as $key => $t) {
            $row = $system[$key] ?? null;
            $out[] = [
                'id' => $row?->id, 'key' => $key, 'is_system' => true, 'custom_only' => !empty($t['custom']),
                'title' => $t['title'], 'stage' => $t['stage'], 'category' => $t['category'],
                'header' => $t['header'], 'body' => $t['body'], 'footer' => $t['footer'], 'buttons' => $t['buttons'],
                'meta_status' => !empty($t['custom']) ? 'needs_review' : ($row?->meta_status ?? 'draft'),
                'meta_reason' => $row?->meta_reason,
            ];
        }
        foreach (WhatsAppTemplate::where('event_id', $event_id)->orderByDesc('id')->get() as $row) {
            $out[] = $this->format($row);
        }

        return $out;
    }

    public function format(WhatsAppTemplate $t): array
    {
        return [
            'id' => $t->id, 'key' => $t->key, 'base_key' => $t->base_key, 'is_system' => (bool) $t->is_system, 'custom_only' => false,
            'title' => $t->title, 'stage' => $t->stage, 'category' => $t->category,
            'header' => $t->header, 'body' => $t->body, 'footer' => $t->footer, 'buttons' => $t->buttons ?? [],
            'meta_status' => $t->meta_status, 'meta_reason' => $t->meta_reason,
            'submitted_at' => optional($t->submitted_at)->toIso8601String(),
            'reviewed_at' => optional($t->reviewed_at)->toIso8601String(),
        ];
    }

    /** Validate + normalize editable content. */
    public static function cleanContent(array $data, ?array $base = null): array
    {
        $out = [];
        $max = (int) config('whatsapp_messaging.max_body_chars', 1024);
        $body = trim((string) ($data['body'] ?? $base['body'] ?? ''));
        if ($body === '') {
            throw new MessagingHttpException('Message body is required', 422);
        }
        if (mb_strlen($body) > $max) {
            throw new MessagingHttpException("Message body is limited to {$max} characters", 422);
        }
        foreach (['header' => 60, 'footer' => 60] as $f => $len) {
            $v = array_key_exists($f, $data) ? $data[$f] : ($base[$f] ?? null);
            $v = $v === null ? null : trim((string) $v);
            if ($v !== null && mb_strlen($v) > $len) {
                throw new MessagingHttpException(ucfirst($f) . " is limited to {$len} characters", 422);
            }
            $out[$f] = $v === '' ? null : $v;
        }
        preg_match_all('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', $body . ' ' . ($out['header'] ?? ''), $m);
        $unknown = array_diff(array_unique($m[1]), array_keys(TemplateCatalog::VARIABLES));
        if ($unknown) {
            throw new MessagingHttpException('Unknown variable: ' . implode(', ', $unknown), 422, ['unknown_variables' => array_values($unknown)]);
        }
        $buttons = array_key_exists('buttons', $data) ? (array) $data['buttons'] : ($base['buttons'] ?? []);
        $clean = [];
        foreach (array_slice($buttons, 0, (int) config('whatsapp_messaging.max_buttons', 3)) as $b) {
            $b = is_string($b) ? ['type' => 'quick_reply', 'text' => $b] : (array) $b;
            $text = trim(mb_substr((string) ($b['text'] ?? ''), 0, 25));
            if ($text === '') {
                continue;
            }
            $type = ($b['type'] ?? 'quick_reply') === 'url' ? 'url' : 'quick_reply';
            $row = ['type' => $type, 'text' => $text];
            if ($type === 'url') {
                $row['link'] = in_array($b['link'] ?? '', ['hub_link', 'album_link', 'rsvp_link', 'gift_link', 'waze_link'], true) ? $b['link'] : 'hub_link';
            } else {
                $row['payload'] = in_array($b['payload'] ?? '', ['rsvp_yes', 'rsvp_maybe', 'rsvp_no', 'opt_out'], true) ? $b['payload'] : 'reply';
            }
            $clean[] = $row;
        }
        $out['buttons'] = $clean;
        $out['body'] = $body;

        return $out;
    }

    /** Is the content identical to an approved/system template (no Meta review needed)? */
    public function systemMatch(?string $template_key, array $content): ?WhatsAppTemplate
    {
        if (!$template_key) {
            return null;
        }
        $this->ensureSystemTemplates();
        $sys = WhatsAppTemplate::whereNull('event_id')->where('key', $template_key)->first();
        if (!$sys) {
            return null;
        }

        return self::sameContent($sys->toArray(), $content) ? $sys : null;
    }

    public static function sameContent(array $a, array $b): bool
    {
        $norm = fn ($x) => [
            trim((string) ($x['header'] ?? '')), trim((string) ($x['body'] ?? '')), trim((string) ($x['footer'] ?? '')),
            array_map(fn ($btn) => [($btn['type'] ?? 'quick_reply'), trim((string) ($btn['text'] ?? ''))], (array) ($x['buttons'] ?? [])),
        ];

        return $norm($a) === $norm($b);
    }

    /**
     * Template for a send's content: the ready template when unchanged, otherwise an event custom template
     * (reused when identical content was already submitted; otherwise created + submitted to Meta).
     */
    public function resolveForContent(int $event_id, ?string $template_key, string $title, string $category, array $content): WhatsAppTemplate
    {
        if ($sys = $this->systemMatch($template_key, $content)) {
            return $sys;
        }
        $hash = substr(sha1(json_encode([$category, $content['header'], $content['body'], $content['footer'], $content['buttons']], JSON_UNESCAPED_UNICODE)), 0, 12);
        $key = 'c_' . $hash;
        $existing = WhatsAppTemplate::where('event_id', $event_id)->where('key', $key)->first();
        if ($existing && $existing->meta_status !== 'rejected') {
            return $existing;
        }

        $tpl = $existing ?? new WhatsAppTemplate(['event_id' => $event_id, 'key' => $key]);
        $tpl->fill([
            'base_key' => $template_key, 'title' => mb_substr($title, 0, 160), 'stage' => 'manual',
            'category' => $category === 'utility' ? 'utility' : 'marketing',
            'header' => $content['header'], 'body' => $content['body'], 'footer' => $content['footer'], 'buttons' => $content['buttons'],
            'meta_name' => config('whatsapp_messaging.meta.template_prefix', 'snapshare') . '_e' . $event_id . '_' . $hash,
            'is_system' => false, 'meta_reason' => null,
        ]);
        $this->submit($tpl);

        return $tpl;
    }

    public function submit(WhatsAppTemplate $tpl): void
    {
        $res = $this->provider->submitTemplate($tpl->toArray());
        $tpl->meta_template_id = $res['id'] ?? $tpl->meta_template_id;
        $tpl->meta_status = $res['status'] ?? 'pending';
        $tpl->meta_reason = $res['reason'] ?? null;
        $tpl->submitted_at = now();
        if (in_array($tpl->meta_status, ['approved', 'rejected'], true)) {
            $tpl->reviewed_at = now();
        }
        $tpl->save();
    }

    /** Poll provider for pending templates (mock auto-approves after the configured delay). */
    public function syncPending(?int $event_id = null): int
    {
        $q = WhatsAppTemplate::where('meta_status', 'pending');
        if ($event_id) {
            $q->where(fn ($w) => $w->where('event_id', $event_id)->orWhereNull('event_id'));
        }
        $changed = 0;
        foreach ($q->limit(200)->get() as $tpl) {
            $res = $this->provider->templateStatus($tpl->toArray() + ['submitted_at' => $tpl->submitted_at]);
            if (($res['status'] ?? 'pending') !== 'pending') {
                $tpl->meta_status = $res['status'];
                $tpl->meta_reason = $res['reason'] ?? null;
                $tpl->reviewed_at = now();
                $tpl->save();
                $changed++;
            }
        }

        return $changed;
    }

    /** Mock/dev only: force a review result. */
    public function forceStatus(WhatsAppTemplate $tpl, string $status, ?string $reason = null): WhatsAppTemplate
    {
        $tpl->meta_status = $status;
        $tpl->meta_reason = $status === 'rejected' ? ($reason ?: 'Rejected in mock review') : null;
        $tpl->reviewed_at = now();
        $tpl->save();

        return $tpl;
    }

    /** Submit SnapShare system templates to Meta (one-time, after WABA setup). */
    public function submitSystemTemplates(): array
    {
        $this->ensureSystemTemplates();
        $out = [];
        foreach (WhatsAppTemplate::whereNull('event_id')->whereIn('meta_status', ['draft', 'rejected'])->get() as $tpl) {
            $this->submit($tpl);
            $out[$tpl->key] = $tpl->meta_status;
        }

        return $out;
    }
}
