<?php

namespace App\Services\Seating;

use App\Exceptions\SeatingHttpException;
use App\Models\EventGuest;
use App\Models\SeatingAssignment;
use App\Models\SeatingConstraint;
use App\Models\SeatingPlan;
use App\Models\SeatingTable;
use App\Services\Enums\EventGuestStatusEnum;
use App\Services\Guests\GuestCrmService;
use Illuminate\Support\Facades\DB;

/**
 * Seating floor plan: tables, fixtures, assignments, constraints, warnings.
 * Autosave sends the whole plan with the revision it was based on (409 on conflict).
 */
class SeatingService
{
    public const FIXTURE_KINDS = ['dance', 'stage', 'bar', 'dj', 'door', 'buffet', 'chuppah', 'label'];
    public const SHAPES = ['round', 'rect'];

    public function __construct(private ?GuestCrmService $crm = null)
    {
        $this->crm ??= new GuestCrmService();
    }

    public function crm(): GuestCrmService
    {
        return $this->crm;
    }

    public function state(int $event_id, int $user_id): array
    {
        $this->crm->assertAccess($event_id, $user_id);
        $entitlement = SeatingEntitlement::describe($event_id);
        $guests = $this->crm->list($event_id, $user_id);

        $plan = SeatingPlan::where('event_id', $event_id)->first();
        $state = $this->planState($event_id, $plan);

        return array_merge($state, [
            'guests'      => $guests['guests'],
            'groups'      => $guests['groups'],
            'stats'       => $guests['stats'],
            'entitlement' => $entitlement,
            'ai'          => (new SeatingAiService($this))->quota($event_id),
        ]);
    }

    public function planState(int $event_id, ?SeatingPlan $plan): array
    {
        $tables = $plan ? SeatingTable::where('plan_id', $plan->id)->orderBy('sort_order')->orderBy('id')->get() : collect();
        $assignments = $plan ? SeatingAssignment::where('plan_id', $plan->id)->orderBy('id')->get(['guest_id', 'table_id', 'seat']) : collect();
        $constraints = $this->constraints($event_id);

        $formattedTables = $tables->map(fn (SeatingTable $t) => $this->formatTable($t))->values()->all();
        $formattedAssignments = $assignments->map(fn ($a) => [
            'guest_id' => (int) $a->guest_id,
            'table_id' => (int) $a->table_id,
            'seat'     => $a->seat === null ? null : (int) $a->seat,
        ])->values()->all();

        return [
            'revision'    => $plan ? (int) $plan->revision : 0,
            'tables'      => $formattedTables,
            'fixtures'    => $plan ? array_values($plan->canvas['fixtures'] ?? []) : [],
            'assignments' => $formattedAssignments,
            'constraints' => $constraints,
            'warnings'    => $this->warnings($event_id, $formattedTables, $formattedAssignments, $constraints),
            'updated_at'  => $plan ? optional($plan->updated_at)->toIso8601String() : null,
        ];
    }

    /**
     * Full-plan autosave.
     * payload: revision, tables[{id?, key, label, shape, seats, side, x, y, w, h, rot}],
     *          fixtures[{id, kind, label, x, y, w, h, rot}], assignments[{guest_id, table_key, seat}]
     */
    public function save(int $event_id, int $user_id, array $payload): array
    {
        $this->crm->assertAccess($event_id, $user_id);
        SeatingEntitlement::assert($event_id);

        return DB::transaction(function () use ($event_id, $payload) {
            $plan = SeatingPlan::where('event_id', $event_id)->lockForUpdate()->first();
            if (!$plan) {
                $plan = SeatingPlan::create(['event_id' => $event_id, 'canvas' => ['fixtures' => []], 'revision' => 0]);
                $plan = SeatingPlan::where('id', $plan->id)->lockForUpdate()->first();
            }

            $base = (int) ($payload['revision'] ?? -1);
            if ($base !== (int) $plan->revision) {
                throw new SeatingHttpException('Seating plan changed elsewhere', 409, [
                    'conflict' => true,
                    'state'    => $this->planState($event_id, $plan),
                ]);
            }

            // ── tables
            $existing = SeatingTable::where('plan_id', $plan->id)->get()->keyBy('id');
            $key_map = [];
            $keep_ids = [];
            $tables_in = array_values($payload['tables'] ?? []);
            if (count($tables_in) > config('seating.max_tables', 200)) {
                throw new SeatingHttpException('Too many tables', 422);
            }
            $min = (int) config('seating.min_seats_per_table', 2);
            $max = (int) config('seating.max_seats_per_table', 30);

            foreach ($tables_in as $i => $t) {
                $attrs = [
                    'label'      => mb_substr(trim((string) ($t['label'] ?? '')) ?: ('שולחן ' . ($i + 1)), 0, 60),
                    'shape'      => in_array($t['shape'] ?? 'round', self::SHAPES, true) ? $t['shape'] : 'round',
                    'seats'      => max($min, min($max, (int) ($t['seats'] ?? 10))),
                    'side'       => in_array($t['side'] ?? null, GuestCrmService::SIDES, true) ? $t['side'] : null,
                    'x'          => $this->clampInt($t['x'] ?? 0, -500, 5000),
                    'y'          => $this->clampInt($t['y'] ?? 0, -500, 5000),
                    'w'          => $this->clampInt($t['w'] ?? 160, 40, 1200),
                    'h'          => $this->clampInt($t['h'] ?? 160, 40, 1200),
                    'rot'        => ((int) round((float) ($t['rot'] ?? 0)) % 360 + 360) % 360,
                    'sort_order' => $i,
                ];
                $id = isset($t['id']) ? (int) $t['id'] : 0;
                if ($id && $existing->has($id)) {
                    $model = $existing[$id];
                    $model->fill($attrs)->save();
                } else {
                    $model = SeatingTable::create(array_merge(['plan_id' => $plan->id], $attrs));
                }
                $keep_ids[] = $model->id;
                $key = (string) ($t['key'] ?? ('t' . $model->id));
                $key_map[$key] = $model->id;
                $key_map['t' . $model->id] = $model->id;
            }
            SeatingTable::where('plan_id', $plan->id)->whereNotIn('id', $keep_ids ?: [0])->delete();

            // ── fixtures
            $fixtures = [];
            foreach (array_slice(array_values($payload['fixtures'] ?? []), 0, 60) as $f) {
                $kind = in_array($f['kind'] ?? '', self::FIXTURE_KINDS, true) ? $f['kind'] : 'label';
                $fixtures[] = [
                    'id'    => mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($f['id'] ?? '')) ?: ('f' . count($fixtures)), 0, 40),
                    'kind'  => $kind,
                    'label' => mb_substr(trim((string) ($f['label'] ?? '')), 0, 40),
                    'x'     => $this->clampInt($f['x'] ?? 0, -500, 5000),
                    'y'     => $this->clampInt($f['y'] ?? 0, -500, 5000),
                    'w'     => $this->clampInt($f['w'] ?? 120, 30, 2000),
                    'h'     => $this->clampInt($f['h'] ?? 80, 30, 2000),
                    'rot'   => ((int) round((float) ($f['rot'] ?? 0)) % 360 + 360) % 360,
                ];
            }
            $plan->canvas = array_merge($plan->canvas ?? [], ['fixtures' => $fixtures]);

            // ── assignments
            $rows = [];
            foreach (array_values($payload['assignments'] ?? []) as $a) {
                $tkey = (string) ($a['table_key'] ?? (isset($a['table_id']) ? 't' . (int) $a['table_id'] : ''));
                if (!isset($key_map[$tkey])) {
                    continue;
                }
                $rows[(int) $a['guest_id']] = [
                    'table_id' => $key_map[$tkey],
                    'seat'     => isset($a['seat']) && $a['seat'] !== null ? (int) $a['seat'] : null,
                ];
            }
            $dropped = $this->writeAssignments($event_id, $plan, $rows);

            $plan->revision = (int) $plan->revision + 1;
            $plan->save();

            $state = $this->planState($event_id, $plan->fresh());

            return array_merge($state, ['key_map' => $key_map, 'dropped' => $dropped]);
        });
    }

    /**
     * Replace all assignments for a plan. Ignores guests that are not in this event
     * or are declined/cancelled; normalises seat blocks (overflow → seat null).
     *
     * @param array $rows guest_id => ['table_id' => int, 'seat' => ?int]
     * @return int[] guest ids that were dropped
     */
    public function writeAssignments(int $event_id, SeatingPlan $plan, array $rows): array
    {
        $guests = EventGuest::where('event_id', $event_id)
            ->whereIn('id', array_keys($rows) ?: [0])
            ->get(['id', 'status', 'party_size'])
            ->keyBy('id');
        $tables = SeatingTable::where('plan_id', $plan->id)->get(['id', 'seats'])->keyBy('id');

        $dropped = [];
        $per_table = [];
        foreach ($rows as $gid => $r) {
            $g = $guests[$gid] ?? null;
            if (!$g || in_array((int) $g->status, [EventGuestStatusEnum::DECLINED, EventGuestStatusEnum::CANCELLED], true) || !$tables->has($r['table_id'])) {
                $dropped[] = (int) $gid;
                continue;
            }
            $per_table[$r['table_id']][] = ['guest_id' => (int) $gid, 'party' => (int) $g->party_size, 'seat' => $r['seat']];
        }

        SeatingAssignment::where('plan_id', $plan->id)->delete();
        $now = now();
        $insert = [];
        foreach ($per_table as $tid => $items) {
            $seats = SeatLayout::normalize((int) $tables[$tid]->seats, $items);
            foreach ($items as $it) {
                $insert[] = [
                    'plan_id'    => $plan->id,
                    'table_id'   => $tid,
                    'guest_id'   => $it['guest_id'],
                    'seat'       => $seats[$it['guest_id']],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            SeatingAssignment::insert($chunk);
        }

        return $dropped;
    }

    // ───────────────────────── constraints ─────────────────────────

    public function constraints(int $event_id): array
    {
        return SeatingConstraint::where('event_id', $event_id)->orderBy('id')->get()
            ->map(fn (SeatingConstraint $c) => [
                'id'        => $c->id,
                'type'      => $c->type,
                'guest_ids' => array_values(array_map('intval', $c->guest_ids ?? [])),
                'note'      => $c->note,
            ])->values()->all();
    }

    public function createConstraint(int $event_id, int $user_id, array $data): array
    {
        $this->crm->assertAccess($event_id, $user_id);
        SeatingEntitlement::assert($event_id);
        $ids = $this->constraintGuestIds($event_id, $data['guest_ids'] ?? []);
        if (SeatingConstraint::where('event_id', $event_id)->count() >= 300) {
            throw new SeatingHttpException('Too many constraints', 422);
        }
        $c = SeatingConstraint::create([
            'event_id'  => $event_id,
            'type'      => $data['type'] === 'apart' ? 'apart' : 'together',
            'guest_ids' => $ids,
            'note'      => isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 200) : null,
        ]);

        return collect($this->constraints($event_id))->firstWhere('id', $c->id);
    }

    public function updateConstraint(int $event_id, int $constraint_id, int $user_id, array $data): array
    {
        $this->crm->assertAccess($event_id, $user_id);
        SeatingEntitlement::assert($event_id);
        $c = SeatingConstraint::where('event_id', $event_id)->find($constraint_id);
        if (!$c) {
            throw new SeatingHttpException('Constraint not found', 404);
        }
        if (array_key_exists('type', $data)) {
            $c->type = $data['type'] === 'apart' ? 'apart' : 'together';
        }
        if (array_key_exists('guest_ids', $data)) {
            $c->guest_ids = $this->constraintGuestIds($event_id, $data['guest_ids']);
        }
        if (array_key_exists('note', $data)) {
            $c->note = $data['note'] === null ? null : mb_substr(trim((string) $data['note']), 0, 200);
        }
        $c->save();

        return collect($this->constraints($event_id))->firstWhere('id', $c->id);
    }

    public function deleteConstraint(int $event_id, int $constraint_id, int $user_id): void
    {
        $this->crm->assertAccess($event_id, $user_id);
        SeatingEntitlement::assert($event_id);
        $deleted = SeatingConstraint::where('event_id', $event_id)->where('id', $constraint_id)->delete();
        if (!$deleted) {
            throw new SeatingHttpException('Constraint not found', 404);
        }
    }

    private function constraintGuestIds(int $event_id, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) < 2 || count($ids) > 30) {
            throw new SeatingHttpException('A constraint needs 2–30 guests', 422);
        }
        $valid = EventGuest::where('event_id', $event_id)->whereIn('id', $ids)->count();
        if ($valid !== count($ids)) {
            throw new SeatingHttpException('Guest not found', 404);
        }
        sort($ids);

        return $ids;
    }

    // ───────────────────────── warnings ─────────────────────────

    /**
     * Warn-only checks: over capacity, unseated in a full table, together split, apart at the same table.
     */
    public function warnings(int $event_id, array $tables, array $assignments, array $constraints): array
    {
        $party = EventGuest::where('event_id', $event_id)->pluck('party_size', 'id')->all();
        $table_of = [];
        $load = [];
        $overflow = [];
        foreach ($assignments as $a) {
            $table_of[$a['guest_id']] = $a['table_id'];
            $load[$a['table_id']] = ($load[$a['table_id']] ?? 0) + (int) ($party[$a['guest_id']] ?? 1);
            if ($a['seat'] === null) {
                $overflow[$a['table_id']][] = $a['guest_id'];
            }
        }

        $out = [];
        foreach ($tables as $t) {
            $used = $load[$t['id']] ?? 0;
            if ($used > $t['seats'] || !empty($overflow[$t['id']])) {
                $out[] = [
                    'type'      => 'over_capacity',
                    'table_id'  => $t['id'],
                    'guest_ids' => $overflow[$t['id']] ?? [],
                    'message'   => $t['label'] . ': ' . $used . '/' . $t['seats'] . ' מקומות',
                ];
            }
        }

        foreach ($constraints as $c) {
            $ids = $c['guest_ids'];
            if ($c['type'] === 'together') {
                $seated = array_filter($ids, fn ($id) => isset($table_of[$id]));
                $distinct = array_unique(array_map(fn ($id) => $table_of[$id], $seated));
                if ($seated !== [] && (count($distinct) > 1 || count($seated) < count($ids))) {
                    $out[] = ['type' => 'together', 'constraint_id' => $c['id'], 'guest_ids' => $ids, 'message' => 'אורחים שצריכים לשבת יחד מפוצלים'];
                }
            } else {
                $tables_seen = [];
                foreach ($ids as $id) {
                    if (isset($table_of[$id])) {
                        $tables_seen[$table_of[$id]][] = $id;
                    }
                }
                foreach ($tables_seen as $tid => $same) {
                    if (count($same) > 1) {
                        $out[] = ['type' => 'apart', 'constraint_id' => $c['id'], 'table_id' => $tid, 'guest_ids' => $same, 'message' => 'אורחים שצריכים לשבת בנפרד יושבים באותו שולחן'];
                    }
                }
            }
        }

        return $out;
    }

    public function formatTable(SeatingTable $t): array
    {
        return [
            'id'    => $t->id,
            'key'   => 't' . $t->id,
            'label' => $t->label,
            'shape' => $t->shape,
            'seats' => (int) $t->seats,
            'side'  => $t->side,
            'x'     => (int) $t->x,
            'y'     => (int) $t->y,
            'w'     => (int) $t->w,
            'h'     => (int) $t->h,
            'rot'   => (int) $t->rot,
        ];
    }

    private function clampInt($v, int $min, int $max): int
    {
        return max($min, min($max, (int) round((float) $v)));
    }
}
