<?php

namespace App\Services\Seating;

use App\Exceptions\SeatingHttpException;
use App\Models\Event;
use App\Models\SeatingPlan;
use App\Models\SeatingTable;
use App\Services\Enums\EventGuestStatusEnum;
use App\Services\Messaging\MessagingContext;
use App\Services\Messaging\VariableResolver;
use Illuminate\Support\Facades\DB;

/**
 * Public "your table" page (/t/{token}). Shows only this guest's table (no other guests' names);
 * the optional map shows table shapes/labels with this guest's table highlighted.
 */
class TablePageService
{
    public function show(string $token): array
    {
        $guest = TableToken::resolve($token);
        if (!$guest || !config('seating.enabled', false)) {
            throw new SeatingHttpException('This link is not valid', 404);
        }
        $event = Event::find($guest->event_id);
        if (!$event) {
            throw new SeatingHttpException('This link is not valid', 404);
        }
        $vars = (new VariableResolver($event, MessagingContext::settings((int) $event->id)))->forGuest($guest);

        $base = [
            'guest' => ['first_name' => $vars['first_name'], 'party_size' => max(1, (int) $guest->party_size)],
            'event' => [
                'title' => $vars['event_title'], 'couple_names' => $vars['couple_names'], 'date' => $vars['event_date'],
                'day' => $vars['event_day'], 'time' => $vars['event_time'], 'venue_name' => $vars['venue_name'],
                'waze_link' => $vars['waze_link'], 'hub_link' => $vars['hub_link'],
            ],
            'table' => null,
            'map' => null,
        ];

        if (!SeatingEntitlement::enabled((int) $event->id)) {
            return $base + ['state' => 'unavailable'];
        }
        if ((int) $guest->status === EventGuestStatusEnum::DECLINED || (int) $guest->status === EventGuestStatusEnum::CANCELLED) {
            return $base + ['state' => 'declined'];
        }
        $plan = SeatingPlan::where('event_id', $event->id)->first();
        $row = $plan ? DB::table('seating_assignments')->where('plan_id', $plan->id)->where('guest_id', $guest->id)->first() : null;
        if (!$row) {
            return $base + ['state' => 'unassigned'];
        }

        $tables = SeatingTable::where('plan_id', $plan->id)->orderBy('sort_order')->orderBy('id')->get();
        $mine = $tables->firstWhere('id', $row->table_id);
        $number = $tables->search(fn ($t) => (int) $t->id === (int) $row->table_id);
        $base['table'] = [
            'label' => (string) $mine?->label,
            'number' => $number === false ? null : $number + 1,
            'seats' => (int) ($mine?->seats ?? 0),
            'side' => $mine?->side,
        ];
        if (config('seating.table_page.show_map', true) && $tables->count() > 0) {
            $base['map'] = [
                'tables' => $tables->map(fn ($t) => [
                    'label' => (string) $t->label, 'shape' => (string) $t->shape, 'x' => (int) $t->x, 'y' => (int) $t->y,
                    'w' => (int) $t->w, 'h' => (int) $t->h, 'rot' => (int) $t->rot, 'mine' => (int) $t->id === (int) $row->table_id,
                ])->values()->all(),
                'fixtures' => array_values(array_map(fn ($f) => [
                    'kind' => (string) ($f['kind'] ?? ''), 'label' => (string) ($f['label'] ?? ''), 'x' => (int) ($f['x'] ?? 0),
                    'y' => (int) ($f['y'] ?? 0), 'w' => (int) ($f['w'] ?? 0), 'h' => (int) ($f['h'] ?? 0), 'rot' => (int) ($f['rot'] ?? 0),
                ], (array) ($plan->canvas['fixtures'] ?? []))),
            ];
        }

        return $base + ['state' => 'assigned'];
    }
}
