<?php

namespace App\Services\EventHub;

use App\Models\Event;
use App\Models\EventHub;
use App\Services\Enums\MessagesEnum;
use Exception;
use Illuminate\Http\Response;

class EventHubSlugValidator
{
    public function validate(?string $slug, int $event_id): void
    {
        if ($slug === null || $slug === '') {
            return;
        }

        $slug = strtolower(trim($slug));
        $min = (int) config('event_hub.slug_min_length', 3);
        $max = (int) config('event_hub.slug_max_length', 100);

        if (strlen($slug) < $min || strlen($slug) > $max) {
            throw new Exception(MessagesEnum::EVENT_HUB_INVALID_SLUG, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $slug)) {
            throw new Exception(MessagesEnum::EVENT_HUB_INVALID_SLUG, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reserved = array_map('strtolower', config('event_hub.reserved_slugs', []));

        if (in_array($slug, $reserved, true)) {
            throw new Exception(MessagesEnum::EVENT_HUB_SLUG_RESERVED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $taken = EventHub::where('slug', $slug)
            ->where('event_id', '!=', $event_id)
            ->exists();

        if ($taken) {
            throw new Exception(MessagesEnum::EVENT_HUB_SLUG_TAKEN, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pathCollision = Event::where('path', $slug)->where('id', '!=', $event_id)->exists();

        if ($pathCollision) {
            throw new Exception(MessagesEnum::EVENT_HUB_SLUG_TAKEN, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Non-throwing availability check for slug-check endpoint.
     *
     * @return array{slug: string, available: bool, reason: string|null}
     */
    public function availability(?string $slug, int $event_id): array
    {
        $normalized = $this->normalize($slug);

        if ($normalized === null) {
            return ['slug' => '', 'available' => false, 'reason' => 'invalid'];
        }

        $min = (int) config('event_hub.slug_min_length', 3);
        $max = (int) config('event_hub.slug_max_length', 100);
        $length = strlen($normalized);

        if ($length < $min) {
            return ['slug' => $normalized, 'available' => false, 'reason' => 'too_short'];
        }

        if ($length > $max) {
            return ['slug' => $normalized, 'available' => false, 'reason' => 'too_long'];
        }

        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $normalized)) {
            return ['slug' => $normalized, 'available' => false, 'reason' => 'invalid'];
        }

        $reserved = array_map('strtolower', config('event_hub.reserved_slugs', []));

        if (in_array($normalized, $reserved, true)) {
            return ['slug' => $normalized, 'available' => false, 'reason' => 'reserved'];
        }

        $ownHub = EventHub::where('event_id', $event_id)->first();
        if ($ownHub && $ownHub->slug === $normalized) {
            return ['slug' => $normalized, 'available' => true, 'reason' => null];
        }

        $taken = EventHub::where('slug', $normalized)
            ->where('event_id', '!=', $event_id)
            ->exists();

        if ($taken) {
            return ['slug' => $normalized, 'available' => false, 'reason' => 'taken'];
        }

        $pathCollision = Event::where('path', $normalized)->where('id', '!=', $event_id)->exists();

        if ($pathCollision) {
            return ['slug' => $normalized, 'available' => false, 'reason' => 'taken'];
        }

        return ['slug' => $normalized, 'available' => true, 'reason' => null];
    }

    public function normalize(?string $slug): ?string
    {
        if ($slug === null || trim($slug) === '') {
            return null;
        }

        return strtolower(trim($slug));
    }
}
