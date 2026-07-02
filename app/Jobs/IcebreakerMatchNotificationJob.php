<?php

namespace App\Jobs;

use App\Models\IcebreakerMatch;
use App\Services\Helpers\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IcebreakerMatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $match_id,
    ) {}

    public function handle(): void
    {
        $match = IcebreakerMatch::with(['profileLow', 'profileHigh'])->find($this->match_id);

        if (!$match) {
            return;
        }

        LogService::init()->info('Icebreaker match notification stub', [
            'match_id'  => $match->id,
            'event_id'  => $match->event_id,
            'profiles'  => [$match->profile_low_id, $match->profile_high_id],
        ]);
    }
}
