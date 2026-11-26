<?php

namespace App\Services\Messaging;

use App\Models\Event;
use App\Models\EventGuest;
use App\Models\EventHub;
use App\Models\EventWhatsAppSetting;
use App\Models\SeatingPlan;
use App\Services\Seating\TableToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves template variables at render time (dispatch re-reads guest + seating right before each send,
 * so a table change at 11:00 is reflected in the 14:00 message).
 */
class VariableResolver
{
    private const DAYS = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];

    private array $eventVars = [];

    private array $tables = [];

    public function __construct(private Event $event, private ?EventWhatsAppSetting $settings = null)
    {
        $this->eventVars = $this->buildEventVars();
        $this->tables = $this->loadTables();
    }

    public function eventVars(): array
    {
        return $this->eventVars;
    }

    public function forGuest(EventGuest $guest): array
    {
        $name = trim((string) $guest->full_name);
        $first = $name === '' ? '' : preg_split('/\s+/u', $name)[0];

        return $this->eventVars + [
            'first_name'  => $first,
            'full_name'   => $name,
            'party_size'  => (string) max(1, (int) $guest->party_size),
            'table_label' => $this->tables[$guest->id] ?? '—',
            'rsvp_link'   => RsvpToken::link($guest),
            'table_link'  => TableToken::link($guest),
        ];
    }

    /** Sample vars for previews when no guest is selected. */
    public function sample(): array
    {
        return $this->eventVars + [
            'first_name' => 'נועה', 'full_name' => 'נועה כהן', 'party_size' => '2', 'table_label' => '7',
            'rsvp_link' => rtrim((string) config('app.client_url'), '/') . '/rsvp/…',
            'table_link' => rtrim((string) config('app.client_url'), '/') . '/t/…',
        ];
    }

    public static function render(?string $text, array $vars): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? null;

            return ($v === null || $v === '') ? '—' : (string) $v;
        }, $text);
    }

    /** Variables a template needs that resolve to nothing for this event (warn the host before sending). */
    public function missing(array $texts): array
    {
        $sample = $this->sample();
        $missing = [];
        foreach ($texts as $text) {
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', (string) $text, $m);
            foreach ($m[1] as $var) {
                if (!array_key_exists($var, TemplateCatalog::VARIABLES)) {
                    $missing[$var] = 'unknown';
                } elseif (($sample[$var] ?? '') === '' || ($sample[$var] ?? null) === null) {
                    $missing[$var] = 'empty';
                }
            }
        }

        return $missing;
    }

    private function buildEventVars(): array
    {
        $client = rtrim((string) config('app.client_url'), '/');
        $over = (array) ($this->settings?->variables ?? []);
        $hub = EventHub::where('event_id', $this->event->id)->first();
        $std = (array) (($hub?->hub_config['save_the_date']['content'] ?? null) ?: []);

        $couple = trim(implode(' ו', array_filter([trim((string) ($std['a'] ?? '')), trim((string) ($std['b'] ?? ''))])));
        $tz = config('whatsapp_messaging.timezone', 'Asia/Jerusalem');
        $starts = $this->event->starts_at ? Carbon::parse($this->event->starts_at, 'UTC')->setTimezone($tz) : null;

        $vars = [
            'couple_names'  => $couple !== '' ? $couple : (string) $this->event->name,
            'event_title'   => (string) $this->event->name,
            'event_day'     => $starts ? self::DAYS[$starts->dayOfWeek] : '',
            'event_date'    => $starts ? $starts->format('d.m.Y') : '',
            'event_time'    => $starts ? $starts->format('H:i') : (string) ($std['time'] ?? ''),
            'venue_name'    => (string) ($std['venue'] ?? ''),
            'venue_address' => (string) ($std['city'] ?? ''),
            'waze_link'     => '',
            'hub_link'      => $hub && $hub->slug ? $client . '/h/' . $hub->slug : '',
            'album_link'    => $client . '/event/uploads/' . $this->event->path,
            'gift_link'     => '',
        ];
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $vars) && is_string($v) && trim($v) !== '') {
                $vars[$k] = trim($v);
            }
        }
        if ($vars['waze_link'] === '' && $vars['venue_name'] !== '') {
            $vars['waze_link'] = 'https://waze.com/ul?q=' . rawurlencode(trim($vars['venue_name'] . ' ' . $vars['venue_address']));
        }

        return $vars;
    }

    private function loadTables(): array
    {
        $plan = SeatingPlan::where('event_id', $this->event->id)->first();
        if (!$plan) {
            return [];
        }

        return DB::table('seating_assignments as a')
            ->join('seating_tables as t', 't.id', '=', 'a.table_id')
            ->where('a.plan_id', $plan->id)
            ->pluck('t.label', 'a.guest_id')
            ->map(fn ($l) => (string) $l)
            ->all();
    }
}
