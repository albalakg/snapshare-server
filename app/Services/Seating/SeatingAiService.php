<?php

namespace App\Services\Seating;

use App\Exceptions\SeatingHttpException;
use App\Models\AiSeatingRun;
use App\Models\EventGuest;
use App\Models\EventGuestGroup;
use App\Models\SeatingAssignment;
use App\Models\SeatingPlan;
use App\Models\SeatingTable;
use App\Services\Enums\EventGuestStatusEnum;
use App\Services\Seating\Ai\SeatingAiPrompt;
use App\Services\Seating\Ai\SeatingAiProvider;
use App\Services\Seating\Ai\SeatingAiProviderFactory;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Smart arrange. Every run produces a preview only; the host applies or discards it.
 * engine=ai spends one unit of the per-event quota when it succeeds (failed runs are free);
 * engine=packer is the free deterministic fallback.
 */
class SeatingAiService
{
    public const COUNTED_STATUSES = ['succeeded', 'applied', 'discarded'];

    public function __construct(private SeatingService $seating, private ?SeatingAiProvider $provider = null)
    {
    }

    private function provider(): SeatingAiProvider
    {
        return $this->provider ??= SeatingAiProviderFactory::make();
    }

    public function quota(int $event_id): array
    {
        $base = (int) config('seating.ai.runs_per_event', 5);
        $extra = SeatingEntitlement::extraAiRuns($event_id);
        $included = $base + $extra;
        $used = AiSeatingRun::where('event_id', $event_id)->where('engine', 'ai')->whereIn('status', self::COUNTED_STATUSES)->count();
        $provider = $this->provider();

        return [
            'included'  => $included,
            'base'      => $base,
            'extra'     => $extra,
            'used'      => $used,
            'remaining' => max(0, $included - $used),
            'provider'  => $provider->name(),
            'model'     => $provider->model(),
            'is_mock'   => $provider->name() === 'mock',
            'estimate'  => $this->estimate($event_id),
        ];
    }

    public function estimate(int $event_id): array
    {
        $guests = EventGuest::where('event_id', $event_id)
            ->whereNotIn('status', [EventGuestStatusEnum::DECLINED, EventGuestStatusEnum::CANCELLED])->count();
        $plan = SeatingPlan::where('event_id', $event_id)->first();
        $tables = $plan ? SeatingTable::where('plan_id', $plan->id)->count() : 0;
        $constraints = DB::table('seating_constraints')->where('event_id', $event_id)->count();

        $tokens_in = 700 + $guests * 30 + $tables * 18 + $constraints * 14;
        $tokens_out = 60 + $guests * 20;
        $usd = $this->cost($tokens_in, $tokens_out);

        return [
            'tokens_in'  => $tokens_in,
            'tokens_out' => $tokens_out,
            'cost_usd'   => round($usd, 5),
            'cost_ils'   => round($usd * (float) config('seating.ai.usd_to_ils', 3.7), 3),
        ];
    }

    private function cost(int $in, int $out): float
    {
        if ($this->provider()->name() === 'mock') {
            return 0.0;
        }

        return $in / 1e6 * (float) config('seating.ai.price_in_per_m', 0.15)
            + $out / 1e6 * (float) config('seating.ai.price_out_per_m', 0.60);
    }

    /**
     * options: engine ai|packer, mode fill|reshuffle, include_pending, include_maybe, instructions
     */
    public function run(int $event_id, int $user_id, array $options): array
    {
        $this->seating->crm()->assertAccess($event_id, $user_id);
        SeatingEntitlement::assert($event_id);

        $engine = ($options['engine'] ?? 'ai') === 'packer' ? 'packer' : 'ai';
        $opts = [
            'mode'            => ($options['mode'] ?? 'fill') === 'reshuffle' ? 'reshuffle' : 'fill',
            'include_pending' => (bool) ($options['include_pending'] ?? true),
            'include_maybe'   => (bool) ($options['include_maybe'] ?? true),
            'instructions'    => mb_substr(trim((string) ($options['instructions'] ?? '')), 0, (int) config('seating.ai.max_instructions_chars', 500)),
        ];

        $plan = SeatingPlan::where('event_id', $event_id)->first();
        if (!$plan || SeatingTable::where('plan_id', $plan->id)->count() === 0) {
            throw new SeatingHttpException('Add tables before arranging', 422);
        }

        $problem = $this->buildProblem($event_id, $plan, $opts);
        if (count($problem['pool']) === 0) {
            throw new SeatingHttpException('No guests left to seat', 422);
        }
        if (count($problem['guests']) > (int) config('seating.ai.max_guests', 1500)) {
            throw new SeatingHttpException('Too many guests for one run', 422);
        }

        if ($engine === 'ai') {
            $this->assertCanRunAi($event_id);
        }

        $run = AiSeatingRun::create([
            'event_id'       => $event_id,
            'user_id'        => $user_id,
            'engine'         => $engine,
            'provider'       => $engine === 'ai' ? $this->provider()->name() : 'packer',
            'model'          => $engine === 'ai' ? $this->provider()->model() : null,
            'prompt_version' => $engine === 'ai' ? SeatingAiPrompt::VERSION : null,
            'status'         => 'pending',
            'base_revision'  => (int) $plan->revision,
            'options'        => $opts,
        ]);

        $packer = new SeatingPacker();

        try {
            if ($engine === 'packer') {
                $result = $packer->pack($problem['tables'], $problem['guests'], $problem['constraints'], $problem['kept']);
                $repaired = 0;
            } else {
                $input = $this->aiInput($problem, $opts);
                $tokens_guess = SeatingAiPrompt::estimateTokens(SeatingAiPrompt::system() . SeatingAiPrompt::user($input));
                if ($tokens_guess > (int) config('seating.ai.max_input_tokens', 60000)) {
                    throw new SeatingHttpException('Seating problem is too large for one AI run', 422);
                }

                $raw = $this->provider()->propose($input);
                [$accepted, $ai_unplaced] = $this->validateAi($problem, $raw);
                $keep = $problem['kept'] + $accepted;
                $result = $packer->pack($problem['tables'], $problem['guests'], $problem['constraints'], $keep);
                $repaired = count(array_filter($result['assignments'], fn ($a) => $a['source'] === 'packer'));
                foreach ($result['assignments'] as &$a) {
                    if ($a['source'] === 'packer') {
                        $a['source'] = 'repaired';
                    }
                }
                unset($a);
                // Keep the model's reason for guests it chose not to seat when we couldn't seat them either.
                foreach ($result['unplaced'] as &$u) {
                    if (isset($ai_unplaced[$u['guest_id']])) {
                        $u['reason'] = $ai_unplaced[$u['guest_id']];
                    }
                }
                unset($u);

                $run->tokens_in = (int) ($raw['tokens_in'] ?? 0);
                $run->tokens_out = (int) ($raw['tokens_out'] ?? 0);
                $run->model = (string) ($raw['model'] ?? $run->model);
                $run->cost_usd = $this->cost($run->tokens_in, $run->tokens_out);
            }
        } catch (SeatingHttpException $ex) {
            $run->status = 'failed';
            $run->error_message = $ex->getMessage();
            $run->save();
            throw $ex;
        } catch (Throwable $ex) {
            $run->status = 'failed';
            $run->error_message = mb_substr($ex->getMessage(), 0, 500);
            $run->save();
            throw new SeatingHttpException('Smart arrange failed. The run was not counted.', 502, [
                'run'   => $this->formatRun($run),
                'quota' => $this->quota($event_id),
            ]);
        }

        $proposal = $result['assignments'];
        $run->proposal = $proposal;
        $run->unplaced = $result['unplaced'];
        $run->warnings = $this->seating->warnings(
            $event_id,
            array_map(fn ($t) => ['id' => $t['id'], 'label' => $t['label'], 'seats' => $t['seats']], $problem['tables']),
            array_map(fn ($a) => ['guest_id' => $a['guest_id'], 'table_id' => $a['table_id'], 'seat' => $a['seat']], $proposal),
            $problem['constraints_full']
        );
        $run->status = 'succeeded';
        $run->save();

        return [
            'run'     => $this->formatRun($run, $problem, $repaired),
            'quota'   => $this->quota($event_id),
        ];
    }

    public function apply(int $event_id, int $run_id, int $user_id, bool $force = false): array
    {
        $this->seating->crm()->assertAccess($event_id, $user_id);
        SeatingEntitlement::assert($event_id);

        return DB::transaction(function () use ($event_id, $run_id, $force) {
            $run = AiSeatingRun::where('event_id', $event_id)->lockForUpdate()->find($run_id);
            if (!$run) {
                throw new SeatingHttpException('Run not found', 404);
            }
            if ($run->status !== 'succeeded') {
                throw new SeatingHttpException('This proposal can no longer be applied', 422);
            }
            $plan = SeatingPlan::where('event_id', $event_id)->lockForUpdate()->first();
            if (!$plan) {
                throw new SeatingHttpException('Seating plan not found', 404);
            }
            if ((int) $plan->revision !== (int) $run->base_revision && !$force) {
                throw new SeatingHttpException('The plan changed since this proposal', 409, ['stale' => true]);
            }

            $rows = [];
            foreach ($run->proposal ?? [] as $a) {
                $rows[(int) $a['guest_id']] = ['table_id' => (int) $a['table_id'], 'seat' => $a['seat'] ?? null];
            }
            if ($force && (int) $plan->revision !== (int) $run->base_revision) {
                // Keep current seats of guests the proposal doesn't mention.
                foreach (SeatingAssignment::where('plan_id', $plan->id)->get() as $cur) {
                    $rows[(int) $cur->guest_id] ??= ['table_id' => (int) $cur->table_id, 'seat' => $cur->seat];
                }
            }
            $dropped = $this->seating->writeAssignments($event_id, $plan, $rows);
            $plan->revision = (int) $plan->revision + 1;
            $plan->save();

            $run->status = 'applied';
            $run->applied_at = now();
            $run->save();

            return array_merge($this->seating->planState($event_id, $plan->fresh()), [
                'dropped' => $dropped,
                'run'     => $this->formatRun($run),
                'quota'   => $this->quota($event_id),
            ]);
        });
    }

    public function discard(int $event_id, int $run_id, int $user_id): array
    {
        $this->seating->crm()->assertAccess($event_id, $user_id);
        $run = AiSeatingRun::where('event_id', $event_id)->find($run_id);
        if (!$run) {
            throw new SeatingHttpException('Run not found', 404);
        }
        if ($run->status === 'succeeded') {
            $run->status = 'discarded';
            $run->save();
        }

        return ['run' => $this->formatRun($run), 'quota' => $this->quota($event_id)];
    }

    public function runs(int $event_id, int $user_id): array
    {
        $this->seating->crm()->assertAccess($event_id, $user_id);

        return AiSeatingRun::where('event_id', $event_id)->orderByDesc('id')->limit(20)->get()
            ->map(fn (AiSeatingRun $r) => $this->formatRun($r, null, null, false))->values()->all();
    }

    private function assertCanRunAi(int $event_id): void
    {
        $q = $this->quota($event_id);
        if ($q['remaining'] <= 0) {
            throw new SeatingHttpException('No smart-arrange runs left for this event', 429, ['quota' => $q]);
        }
        $busy = AiSeatingRun::where('event_id', $event_id)->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(2))->exists();
        if ($busy) {
            throw new SeatingHttpException('Another smart-arrange run is in progress', 429);
        }
    }

    /**
     * Current DB state → packer/AI problem.
     */
    private function buildProblem(int $event_id, SeatingPlan $plan, array $opts): array
    {
        $statuses = [EventGuestStatusEnum::CONFIRMED];
        if ($opts['include_pending']) {
            $statuses[] = EventGuestStatusEnum::INVITED;
        }
        if ($opts['include_maybe']) {
            $statuses[] = EventGuestStatusEnum::MAYBE;
        }

        $groups = EventGuestGroup::where('event_id', $event_id)->get()->keyBy('id');
        $current = SeatingAssignment::where('plan_id', $plan->id)->get()->keyBy('guest_id');
        $all = EventGuest::where('event_id', $event_id)
            ->whereNotIn('status', [EventGuestStatusEnum::DECLINED, EventGuestStatusEnum::CANCELLED])
            ->orderBy('id')->get();

        $guests = [];
        $kept = [];
        $pool = [];
        foreach ($all as $g) {
            $group = $g->group_id ? ($groups[$g->group_id] ?? null) : null;
            $isCurrent = $current->has($g->id);
            $eligible = in_array((int) $g->status, $statuses, true);
            if (!$eligible && !($opts['mode'] === 'fill' && $isCurrent)) {
                continue;
            }
            $row = [
                'id'         => $g->id,
                'name'       => (string) $g->full_name,
                'party'      => max(1, (int) $g->party_size),
                'group_id'   => $g->group_id ? (int) $g->group_id : null,
                'group_name' => $group?->name,
                'side'       => $g->side ?: $group?->side,
                'status'     => (int) $g->status,
                'notes'      => $g->notes ? mb_substr((string) $g->notes, 0, 80) : null,
            ];
            $guests[] = $row;
            if ($opts['mode'] === 'fill' && $isCurrent) {
                $a = $current[$g->id];
                $kept[$g->id] = ['table_id' => (int) $a->table_id, 'seat' => $a->seat, 'reason' => null, 'source' => 'kept'];
            } else {
                $pool[] = $g->id;
            }
        }

        $tables = SeatingTable::where('plan_id', $plan->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (SeatingTable $t) => [
                'id' => $t->id, 'label' => $t->label, 'seats' => (int) $t->seats, 'side' => $t->side,
                'x' => $t->x + $t->w / 2, 'y' => $t->y + $t->h / 2,
            ])->values()->all();

        $constraints_full = $this->seating->constraints($event_id);

        return [
            'guests'           => $guests,
            'pool'             => $pool,
            'kept'             => $kept,
            'tables'           => $tables,
            'fixtures'         => $plan->canvas['fixtures'] ?? [],
            'constraints'      => array_map(fn ($c) => ['type' => $c['type'], 'guest_ids' => $c['guest_ids']], $constraints_full),
            'constraints_full' => $constraints_full,
            'current'          => $current->map(fn ($a) => (int) $a->table_id)->all(),
        ];
    }

    private function aiInput(array $problem, array $opts): array
    {
        $party = [];
        foreach ($problem['guests'] as $g) {
            $party[$g['id']] = $g['party'];
        }
        $load = [];
        foreach ($problem['kept'] as $gid => $a) {
            $load[$a['table_id']] = ($load[$a['table_id']] ?? 0) + ($party[$gid] ?? 1);
        }
        $names = ['dance' => 'dance floor', 'stage' => 'stage', 'bar' => 'bar', 'dj' => 'dj', 'door' => 'entrance', 'buffet' => 'buffet', 'chuppah' => 'chuppah'];

        $tables = array_map(function ($t) use ($load, $problem, $names) {
            $near = [];
            foreach ($problem['fixtures'] as $f) {
                if (!isset($names[$f['kind']])) {
                    continue;
                }
                $fx = $f['x'] + $f['w'] / 2;
                $fy = $f['y'] + $f['h'] / 2;
                if (hypot($fx - $t['x'], $fy - $t['y']) < 420) {
                    $near[] = $names[$f['kind']];
                }
            }

            return [
                'id' => $t['id'], 'label' => $t['label'], 'seats' => $t['seats'],
                'free' => max(0, $t['seats'] - ($load[$t['id']] ?? 0)), 'side' => $t['side'],
                'near' => array_values(array_unique($near)),
            ];
        }, $problem['tables']);

        $pool = array_flip($problem['pool']);
        $guests = [];
        foreach ($problem['guests'] as $g) {
            if (!isset($pool[$g['id']])) {
                continue;
            }
            $guests[] = array_filter([
                'id' => $g['id'], 'name' => $g['name'], 'party_size' => $g['party'],
                'group' => $g['group_name'], 'group_id' => $g['group_id'], 'side' => $g['side'],
                'maybe' => $g['status'] === EventGuestStatusEnum::MAYBE ? true : null,
                'notes' => $g['notes'],
            ], fn ($v) => $v !== null);
        }
        // Make sure keys used by the mock exist.
        $guests = array_map(fn ($g) => $g + ['group' => null, 'group_id' => null, 'side' => null], $guests);

        return [
            'mode'         => $opts['mode'],
            'instructions' => $opts['instructions'],
            'tables'       => $tables,
            'guests'       => $guests,
            'constraints'  => $problem['constraints'],
            'kept'         => array_map(fn ($gid, $a) => ['guest_id' => $gid, 'table_id' => $a['table_id']], array_keys($problem['kept']), $problem['kept']),
        ];
    }

    /**
     * Keep only valid, in-capacity picks from the model; the rest are repaired by the packer.
     * @return array{0: array, 1: array} accepted keep-map, model unplaced reasons
     */
    private function validateAi(array $problem, array $raw): array
    {
        $pool = array_flip($problem['pool']);
        $party = [];
        foreach ($problem['guests'] as $g) {
            $party[$g['id']] = $g['party'];
        }
        $free = [];
        foreach ($problem['tables'] as $t) {
            $free[$t['id']] = $t['seats'];
        }
        foreach ($problem['kept'] as $gid => $a) {
            $free[$a['table_id']] = ($free[$a['table_id']] ?? 0) - ($party[$gid] ?? 1);
        }

        $accepted = [];
        foreach ($raw['assignments'] ?? [] as $a) {
            $gid = (int) ($a['guest_id'] ?? 0);
            $tid = (int) ($a['table_id'] ?? 0);
            if (!isset($pool[$gid]) || isset($accepted[$gid]) || !isset($free[$tid])) {
                continue;
            }
            if ($free[$tid] < $party[$gid]) {
                continue;
            }
            $free[$tid] -= $party[$gid];
            $accepted[$gid] = [
                'table_id' => $tid,
                'seat'     => null,
                'reason'   => mb_substr(trim((string) ($a['reason'] ?? '')), 0, 80) ?: null,
                'source'   => 'ai',
            ];
        }
        $unplaced = [];
        foreach ($raw['unplaced'] ?? [] as $u) {
            $gid = (int) ($u['guest_id'] ?? 0);
            if (isset($pool[$gid]) && !isset($accepted[$gid])) {
                $unplaced[$gid] = mb_substr(trim((string) ($u['reason'] ?? '')), 0, 80) ?: 'לא נמצא מקום';
            }
        }

        return [$accepted, $unplaced];
    }

    public function formatRun(AiSeatingRun $run, ?array $problem = null, ?int $repaired = null, bool $withProposal = true): array
    {
        $proposal = $run->proposal ?? [];
        $current = $problem['current'] ?? null;
        $party = [];
        if ($problem) {
            foreach ($problem['guests'] as $g) {
                $party[$g['id']] = $g['party'];
            }
        }
        $summary = null;
        if ($run->status !== 'failed' && $run->status !== 'pending') {
            $tables_used = count(array_unique(array_map(fn ($a) => $a['table_id'], $proposal)));
            $moved = 0;
            $new = 0;
            if ($current !== null) {
                foreach ($proposal as $a) {
                    if (!isset($current[$a['guest_id']])) {
                        $new++;
                    } elseif ($current[$a['guest_id']] !== (int) $a['table_id']) {
                        $moved++;
                    }
                }
            }
            $summary = [
                'assignments' => count($proposal),
                'unplaced'    => count($run->unplaced ?? []),
                'tables_used' => $tables_used,
                'new'         => $current !== null ? $new : null,
                'moved'       => $current !== null ? $moved : null,
                'repaired'    => $repaired,
                'people'      => $party ? array_sum(array_map(fn ($a) => $party[$a['guest_id']] ?? 1, $proposal)) : null,
            ];
        }

        $out = [
            'id'            => $run->id,
            'engine'        => $run->engine,
            'provider'      => $run->provider,
            'model'         => $run->model,
            'status'        => $run->status,
            'counted'       => $run->engine === 'ai' && in_array($run->status, self::COUNTED_STATUSES, true),
            'base_revision' => (int) $run->base_revision,
            'options'       => $run->options,
            'tokens_in'     => (int) $run->tokens_in,
            'tokens_out'    => (int) $run->tokens_out,
            'cost_usd'      => (float) $run->cost_usd,
            'error'         => $run->status === 'failed' ? 'failed' : null,
            'summary'       => $summary,
            'created_at'    => optional($run->created_at)->toIso8601String(),
            'applied_at'    => optional($run->applied_at)->toIso8601String(),
        ];
        if ($withProposal) {
            $out['proposal'] = $proposal;
            $out['unplaced'] = $run->unplaced ?? [];
            $out['warnings'] = $run->warnings ?? [];
        }

        return $out;
    }
}
