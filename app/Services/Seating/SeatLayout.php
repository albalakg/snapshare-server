<?php

namespace App\Services\Seating;

/**
 * Seat-index normalisation. A guest with party_size P occupies a contiguous
 * block [seat, seat + P) at their table (no wrap-around), same as the builder UI.
 */
class SeatLayout
{
    /**
     * @param int   $capacity seats at the table
     * @param array $items    list of ['guest_id' => int, 'party' => int, 'seat' => ?int] in priority order
     * @return array guest_id => ?int seat (null = does not fit, over capacity)
     */
    public static function normalize(int $capacity, array $items): array
    {
        $occupied = array_fill(0, max(0, $capacity), false);
        $result = [];
        $pending = [];

        // Pass 1: honour requested seats when the whole block is free.
        foreach ($items as $it) {
            $p = max(1, (int) $it['party']);
            $s = $it['seat'];
            if ($s !== null && $s >= 0 && $s + $p <= $capacity && self::free($occupied, (int) $s, $p)) {
                self::take($occupied, (int) $s, $p);
                $result[$it['guest_id']] = (int) $s;
            } else {
                $pending[] = $it;
            }
        }

        // Pass 2: first contiguous free block.
        foreach ($pending as $it) {
            $p = max(1, (int) $it['party']);
            $result[$it['guest_id']] = null;
            for ($s = 0; $s + $p <= $capacity; $s++) {
                if (self::free($occupied, $s, $p)) {
                    self::take($occupied, $s, $p);
                    $result[$it['guest_id']] = $s;
                    break;
                }
            }
        }

        return $result;
    }

    private static function free(array $occ, int $s, int $p): bool
    {
        for ($i = $s; $i < $s + $p; $i++) {
            if (!empty($occ[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function take(array &$occ, int $s, int $p): void
    {
        for ($i = $s; $i < $s + $p; $i++) {
            $occ[$i] = true;
        }
    }
}
