<?php

namespace App\Services\Seating;

/**
 * Deterministic, free (non-AI) seating packer. Also used to repair AI proposals.
 *
 *  - "together" constraints are merged into units (union-find) and kept on one table when they fit.
 *  - Each group is packed onto as few tables as possible, preferring tables with the same side.
 *  - "apart" pairs are avoided when any other table fits (warned, never blocked).
 *  - Never exceeds a table's seats; leftovers are returned as unplaced.
 *  - Stable: same input → same output.
 */
class SeatingPacker
{
    /**
     * @param array $tables      [['id','label','seats','side'], ...] in display order
     * @param array $guests      eligible guests [['id','name','party','group_id','group_name','side'], ...]
     * @param array $constraints [['type' => together|apart, 'guest_ids' => [...]], ...]
     * @param array $keep        guest_id => ['table_id' => int, 'seat' => ?int, 'reason' => ?string, 'source' => string]
     *                           assignments that must stay (fill mode / validated AI picks)
     * @return array ['assignments' => [...], 'unplaced' => [...]]
     */
    public function pack(array $tables, array $guests, array $constraints, array $keep = []): array
    {
        $tables = array_values($tables);
        $by_id = [];
        foreach ($guests as $g) {
            $by_id[(int) $g['id']] = $g;
        }
        $table_by_id = [];
        foreach ($tables as $i => $t) {
            $t['_order'] = $i;
            $table_by_id[(int) $t['id']] = $t;
        }

        $free = [];
        $groups_at = []; // table_id => [group_key => people]
        $members_at = []; // table_id => [guest_id => true]
        foreach ($table_by_id as $tid => $t) {
            $free[$tid] = (int) $t['seats'];
            $groups_at[$tid] = [];
            $members_at[$tid] = [];
        }

        $placed = []; // guest_id => [table_id, reason, source, seat]
        foreach ($keep as $gid => $a) {
            $gid = (int) $gid;
            $tid = (int) $a['table_id'];
            if (!isset($by_id[$gid], $table_by_id[$tid])) {
                continue;
            }
            $p = $this->party($by_id[$gid]);
            if ($free[$tid] < $p) {
                continue; // caller's pick no longer fits: re-pack it
            }
            $free[$tid] -= $p;
            $gk = $this->groupKey($by_id[$gid]);
            $groups_at[$tid][$gk] = ($groups_at[$tid][$gk] ?? 0) + $p;
            $members_at[$tid][$gid] = true;
            $placed[$gid] = ['table_id' => $tid, 'seat' => $a['seat'] ?? null, 'reason' => $a['reason'] ?? null, 'source' => $a['source'] ?? 'kept'];
        }

        // together → union-find
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            if (!isset($parent[$x]) || $parent[$x] === $x) {
                $parent[$x] = $x;
                return $x;
            }
            return $parent[$x] = $find($parent[$x]);
        };
        $apart = [];
        foreach ($constraints as $c) {
            $ids = array_values(array_filter(array_map('intval', $c['guest_ids'] ?? []), fn ($id) => isset($by_id[$id])));
            if (count($ids) < 2) {
                continue;
            }
            if (($c['type'] ?? '') === 'together') {
                $root = $find($ids[0]);
                foreach (array_slice($ids, 1) as $id) {
                    $parent[$find($id)] = $root;
                }
            } elseif (($c['type'] ?? '') === 'apart') {
                foreach ($ids as $a) {
                    foreach ($ids as $b) {
                        if ($a !== $b) {
                            $apart[$a][$b] = true;
                        }
                    }
                }
            }
        }

        // Units from the pool (not yet placed)
        $units = [];
        foreach ($by_id as $gid => $g) {
            if (isset($placed[$gid])) {
                continue;
            }
            $root = $find($gid);
            $units[$root][] = $gid;
        }

        // Prefer the table of an already-placed cluster member.
        $cluster_table = [];
        foreach ($placed as $gid => $a) {
            $cluster_table[$find($gid)] = $a['table_id'];
        }

        // Bucket units by group
        $buckets = [];
        foreach ($units as $root => $ids) {
            sort($ids);
            $size = array_sum(array_map(fn ($id) => $this->party($by_id[$id]), $ids));
            $gk = $this->groupKey($by_id[$ids[0]]);
            $buckets[$gk]['units'][] = ['root' => $root, 'ids' => $ids, 'size' => $size];
            $buckets[$gk]['size'] = ($buckets[$gk]['size'] ?? 0) + $size;
            $buckets[$gk]['min_id'] = min($buckets[$gk]['min_id'] ?? PHP_INT_MAX, $ids[0]);
            $buckets[$gk]['side'] = $by_id[$ids[0]]['side'] ?? null;
            $buckets[$gk]['name'] = $by_id[$ids[0]]['group_name'] ?? null;
        }
        uksort($buckets, function ($a, $b) use ($buckets) {
            $ga = str_starts_with($a, 'g:') ? 0 : 1;
            $gb = str_starts_with($b, 'g:') ? 0 : 1;
            return [$ga, -$buckets[$a]['size'], $buckets[$a]['min_id']] <=> [$gb, -$buckets[$b]['size'], $buckets[$b]['min_id']];
        });

        $unplaced = [];
        $assign = function (array $ids, int $tid, string $gk, string $reason) use (&$placed, &$free, &$groups_at, &$members_at, $by_id) {
            foreach ($ids as $id) {
                $p = $this->party($by_id[$id]);
                $free[$tid] -= $p;
                $groups_at[$tid][$gk] = ($groups_at[$tid][$gk] ?? 0) + $p;
                $members_at[$tid][$id] = true;
                $placed[$id] = ['table_id' => $tid, 'seat' => null, 'reason' => $reason, 'source' => 'packer'];
            }
        };

        foreach ($buckets as $gk => $bucket) {
            $side = $bucket['side'];
            $reason = $bucket['name'] ? 'יחד עם ' . $bucket['name'] : ($side ? 'לפי צד' : 'מקום פנוי');
            $all_ids = array_merge(...array_map(fn ($u) => $u['ids'], $bucket['units']));

            // Whole group on one table when possible (unless it contains an "apart" pair).
            $internal_apart = false;
            foreach ($all_ids as $id) {
                if (array_intersect(array_keys($apart[$id] ?? []), $all_ids)) {
                    $internal_apart = true;
                    break;
                }
            }
            $tid = $internal_apart ? null : $this->bestTable($table_by_id, $free, $groups_at, $members_at, $apart, $all_ids, $bucket['size'], $gk, $side, null);
            if ($tid !== null) {
                $assign($all_ids, $tid, $gk, $reason);
                continue;
            }

            // Split by unit, largest first.
            $sorted = $bucket['units'];
            usort($sorted, fn ($a, $b) => [-$a['size'], $a['ids'][0]] <=> [-$b['size'], $b['ids'][0]]);
            foreach ($sorted as $u) {
                $pref = $cluster_table[$u['root']] ?? null;
                $tid = $this->bestTable($table_by_id, $free, $groups_at, $members_at, $apart, $u['ids'], $u['size'], $gk, $side, $pref);
                if ($tid !== null) {
                    $assign($u['ids'], $tid, $gk, $reason);
                    continue;
                }
                // Unit too big for any table: place members individually (together is warned, not blocked).
                foreach ($u['ids'] as $id) {
                    $p = $this->party($by_id[$id]);
                    $tid = $this->bestTable($table_by_id, $free, $groups_at, $members_at, $apart, [$id], $p, $gk, $side, $pref);
                    if ($tid !== null) {
                        $assign([$id], $tid, $gk, $reason);
                    } else {
                        $unplaced[] = ['guest_id' => $id, 'reason' => 'אין מקום פנוי שמתאים ל-' . $p . ' מקומות'];
                    }
                }
            }
        }

        // Seats: kept seats first, then contiguous blocks in placement order.
        $per_table = [];
        foreach ($placed as $gid => $a) {
            $per_table[$a['table_id']][] = ['guest_id' => $gid, 'party' => $this->party($by_id[$gid]), 'seat' => $a['seat']];
        }
        $assignments = [];
        foreach ($per_table as $tid => $items) {
            $seats = SeatLayout::normalize((int) $table_by_id[$tid]['seats'], $items);
            foreach ($items as $it) {
                $a = $placed[$it['guest_id']];
                $assignments[] = [
                    'guest_id' => $it['guest_id'],
                    'table_id' => $tid,
                    'seat'     => $seats[$it['guest_id']] ?? null,
                    'reason'   => $a['reason'],
                    'source'   => $a['source'],
                ];
            }
        }
        usort($assignments, fn ($a, $b) => [$table_by_id[$a['table_id']]['_order'], $a['seat'] ?? 999] <=> [$table_by_id[$b['table_id']]['_order'], $b['seat'] ?? 999]);

        return ['assignments' => $assignments, 'unplaced' => $unplaced];
    }

    private function bestTable(array $tables, array $free, array $groups_at, array $members_at, array $apart, array $ids, int $size, string $gk, ?string $side, ?int $preferred): ?int
    {
        $best = null;
        $best_score = null;
        foreach ($tables as $tid => $t) {
            if ($free[$tid] < $size) {
                continue;
            }
            $score = 0.0;
            if ($preferred !== null && $tid === $preferred) {
                $score += 200;
            }
            if (!empty($groups_at[$tid][$gk])) {
                $score += 60;
            }
            $others = array_diff_key($groups_at[$tid], [$gk => true]);
            if ($others !== [] && str_starts_with($gk, 'g:')) {
                $score -= 8 * count($others); // avoid mixing groups
            }
            if ($side && !empty($t['side'])) {
                $score += $t['side'] === $side ? 25 : ($t['side'] === 'shared' ? 5 : -20);
            }
            foreach ($ids as $id) {
                foreach (array_keys($apart[$id] ?? []) as $other) {
                    if (isset($members_at[$tid][$other])) {
                        $score -= 1000;
                    }
                }
            }
            $score -= ($free[$tid] - $size) * 0.6; // tight fit
            $score -= $t['_order'] * 0.01;          // stable tie-break
            if ($best_score === null || $score > $best_score) {
                $best_score = $score;
                $best = $tid;
            }
        }

        return $best;
    }

    private function party(array $g): int
    {
        return max(1, (int) ($g['party'] ?? 1));
    }

    private function groupKey(array $g): string
    {
        if (!empty($g['group_id'])) {
            return 'g:' . $g['group_id'];
        }

        return 's:' . ($g['side'] ?? 'none');
    }
}
