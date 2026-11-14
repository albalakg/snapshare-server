<?php

namespace App\Services\EventHub;

use Illuminate\Support\Facades\Cache;

class EventHubCacheService
{
    public function remember(string $slug, callable $resolver): array
    {
        $key = $this->cacheKey($slug);
        $ttl = (int) config('event_hub.cache_ttl', 3600);

        return Cache::remember($key, $ttl, $resolver);
    }

    public function forget(?string $slug): void
    {
        if ($slug === null || $slug === '') {
            return;
        }

        Cache::forget($this->cacheKey($slug));
    }

    public function forgetForEvent(int $event_id): void
    {
        $hub = \App\Models\EventHub::where('event_id', $event_id)->first();
        if ($hub) {
            $this->forget($hub->slug);
        }
    }

    private function cacheKey(string $slug): string
    {
        return config('event_hub.cache_prefix', 'hub:slug:') . strtolower($slug);
    }
}
