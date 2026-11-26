<?php

namespace App\Services\Messaging;

use App\Models\EventGuest;

/** Signed per-guest RSVP token (no DB column): base36(guest_id).hmac16. */
class RsvpToken
{
    public static function make(EventGuest $guest): string
    {
        $id = base_convert((string) $guest->id, 10, 36);

        return $id . '.' . self::sig($guest->id, (int) $guest->event_id);
    }

    public static function link(EventGuest $guest): string
    {
        return rtrim((string) config('app.client_url'), '/') . '/rsvp/' . self::make($guest);
    }

    public static function resolve(string $token): ?EventGuest
    {
        if (!preg_match('/^([0-9a-z]{1,12})\.([0-9a-f]{16})$/', $token, $m)) {
            return null;
        }
        $guest = EventGuest::find((int) base_convert($m[1], 36, 10));
        if (!$guest || !hash_equals(self::sig($guest->id, (int) $guest->event_id), $m[2])) {
            return null;
        }

        return $guest;
    }

    private static function sig(int $guest_id, int $event_id): string
    {
        return substr(hash_hmac('sha256', "rsvp|{$event_id}|{$guest_id}", (string) config('app.key')), 0, 16);
    }
}
