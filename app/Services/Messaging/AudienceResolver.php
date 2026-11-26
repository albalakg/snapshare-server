<?php

namespace App\Services\Messaging;

use App\Models\EventGuest;
use App\Models\SeatingPlan;
use Illuminate\Support\Facades\DB;

/**
 * Segment → guest ids. Segment shape:
 * { statuses?: int[], sides?: string[], group_ids?: int[], has_table?: bool, guest_ids?: int[], exclude_guest_ids?: int[] }
 * guest_ids (explicit pick) wins over filters. Guests without a phone or who opted out are reported, never messaged.
 */
class AudienceResolver
{
    public static function normalize(?array $segment): array
    {
        $s = $segment ?? [];
        $ints = fn ($v) => array_values(array_unique(array_map('intval', array_filter((array) $v, fn ($x) => is_numeric($x)))));

        $out = [];
        if (isset($s['statuses'])) {
            $out['statuses'] = array_values(array_intersect($ints($s['statuses']), [0, 1, 2, 3, 4]));
        }
        if (isset($s['sides'])) {
            $out['sides'] = array_values(array_intersect(array_map('strval', (array) $s['sides']), ['bride', 'groom', 'shared', 'none']));
        }
        if (isset($s['group_ids'])) {
            $out['group_ids'] = $ints($s['group_ids']);
        }
        if (array_key_exists('has_table', $s) && $s['has_table'] !== null) {
            $out['has_table'] = (bool) $s['has_table'];
        }
        if (isset($s['guest_ids'])) {
            $out['guest_ids'] = array_slice($ints($s['guest_ids']), 0, 5000);
        }
        if (isset($s['exclude_guest_ids'])) {
            $out['exclude_guest_ids'] = array_slice($ints($s['exclude_guest_ids']), 0, 5000);
        }

        return $out;
    }

    /**
     * @return array{eligible: int[], no_phone: int, opted_out: int, matched: int}
     */
    public function resolve(int $event_id, array $segment): array
    {
        $segment = self::normalize($segment);
        $q = EventGuest::where('event_id', $event_id);

        if (!empty($segment['guest_ids'])) {
            $q->whereIn('id', $segment['guest_ids']);
        } else {
            if (isset($segment['statuses'])) {
                $q->whereIn('status', $segment['statuses'] ?: [-1]);
            }
            if (!empty($segment['sides'])) {
                $sides = $segment['sides'];
                $q->where(function ($w) use ($sides) {
                    $w->whereIn('side', array_diff($sides, ['none']));
                    if (in_array('none', $sides, true)) {
                        $w->orWhereNull('side');
                    }
                });
            }
            if (!empty($segment['group_ids'])) {
                $q->whereIn('group_id', $segment['group_ids']);
            }
            if (array_key_exists('has_table', $segment)) {
                $seated = $this->seatedGuestIds($event_id);
                $segment['has_table'] ? $q->whereIn('id', $seated ?: [0]) : $q->whereNotIn('id', $seated);
            }
        }
        if (!empty($segment['exclude_guest_ids'])) {
            $q->whereNotIn('id', $segment['exclude_guest_ids']);
        }

        $rows = $q->orderBy('id')->get(['id', 'phone_hash', 'wa_opted_out_at']);
        $eligible = [];
        $noPhone = $optedOut = 0;
        foreach ($rows as $g) {
            if (!$g->phone_hash) {
                $noPhone++;
            } elseif ($g->wa_opted_out_at) {
                $optedOut++;
            } else {
                $eligible[] = (int) $g->id;
            }
        }

        return ['eligible' => $eligible, 'no_phone' => $noPhone, 'opted_out' => $optedOut, 'matched' => $rows->count()];
    }

    /** Does a single guest still match (smart stop condition for automation sends)? */
    public function matches(EventGuest $guest, array $segment): bool
    {
        $segment = self::normalize($segment);
        if (!empty($segment['guest_ids'])) {
            return in_array($guest->id, $segment['guest_ids'], true);
        }
        if (isset($segment['statuses']) && !in_array((int) $guest->status, $segment['statuses'], true)) {
            return false;
        }
        if (array_key_exists('has_table', $segment)) {
            $has = in_array($guest->id, $this->seatedGuestIds((int) $guest->event_id), true);
            if ($has !== $segment['has_table']) {
                return false;
            }
        }

        return true;
    }

    private array $seatedCache = [];

    private function seatedGuestIds(int $event_id): array
    {
        if (!isset($this->seatedCache[$event_id])) {
            $plan = SeatingPlan::where('event_id', $event_id)->first();
            $this->seatedCache[$event_id] = $plan
                ? DB::table('seating_assignments')->where('plan_id', $plan->id)->pluck('guest_id')->map(fn ($i) => (int) $i)->all()
                : [];
        }

        return $this->seatedCache[$event_id];
    }
}
