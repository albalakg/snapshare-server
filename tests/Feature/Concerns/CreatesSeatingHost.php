<?php

namespace Tests\Feature\Concerns;

use App\Models\Event;
use App\Models\EventGuest;
use App\Models\User;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Guests\PhoneNormalizer;
use Illuminate\Support\Str;

trait CreatesSeatingHost
{
    protected User $user;

    protected Event $event;

    protected function makeHost(string $prefix = 'seat'): void
    {
        $this->user = $this->makeUser($prefix);
        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = $prefix . '-' . Str::lower(Str::random(10));
        $this->event->name = 'QA Seating';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    protected function makeUser(string $prefix): User
    {
        $u = new User();
        $u->role_id = RoleEnum::USER_ID;
        $u->first_name = 'Seat';
        $u->last_name = 'Host';
        $u->email = $prefix . '-' . uniqid() . '@example.com';
        $u->password = 'Password1';
        $u->status = StatusEnum::ACTIVE;
        $u->save();

        return $u;
    }

    protected function guest(string $name, int $party = 1, array $extra = []): EventGuest
    {
        $phone = $extra['phone'] ?? null;
        unset($extra['phone']);

        return EventGuest::create(array_merge([
            'event_id'   => $this->event->id,
            'full_name'  => $name,
            'phone'      => $phone,
            'phone_hash' => $phone ? PhoneNormalizer::hash($phone) : null,
            'status'     => 1,
            'party_size' => $party,
        ], $extra));
    }

    protected function api(string $method, string $uri, array $data = [])
    {
        return $this->actingAs($this->user, 'api')->json($method, "/api/events/{$this->event->id}" . $uri, $data);
    }
}
