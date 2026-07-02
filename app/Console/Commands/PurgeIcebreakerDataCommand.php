<?php

namespace App\Console\Commands;

use App\Models\IcebreakerConfig;
use App\Models\IcebreakerInteraction;
use App\Models\IcebreakerMatch;
use App\Models\IcebreakerProfile;
use App\Services\Enums\IcebreakerStatusEnum;
use App\Services\Helpers\FileService;
use App\Services\Helpers\LogService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeIcebreakerDataCommand extends Command
{
    protected $signature = 'icebreaker:purge';

    protected $description = 'Purge icebreaker data for events in COOLDOWN for more than 48 hours';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subHours(48);

        $configs = IcebreakerConfig::where('status', IcebreakerStatusEnum::COOLDOWN)
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoff)
            ->get();

        foreach ($configs as $config) {
            try {
                $this->purgeEvent($config);
                $this->info("Purged icebreaker data for event {$config->event_id}");
            } catch (Exception $ex) {
                LogService::init()->error($ex, ['event_id' => $config->event_id, 'command' => 'icebreaker:purge']);
                $this->error("Failed to purge event {$config->event_id}: {$ex->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function purgeEvent(IcebreakerConfig $config): void
    {
        DB::transaction(function () use ($config) {
            $profiles = IcebreakerProfile::where('event_id', $config->event_id)->get();

            foreach ($profiles as $profile) {
                $this->deleteAvatarIfStored($profile->avatar_url);
            }

            IcebreakerInteraction::where('event_id', $config->event_id)->delete();
            IcebreakerMatch::where('event_id', $config->event_id)->delete();

            IcebreakerProfile::where('event_id', $config->event_id)->update([
                'display_name'      => 'Purged',
                'avatar_url'        => '',
                'gender'            => null,
                'target_genders'    => null,
                'bio'               => null,
                'instagram_handle'  => null,
                'whatsapp_number'   => null,
                'is_active'         => false,
            ]);

            $config->status = IcebreakerStatusEnum::ARCHIVED;
            $config->purged_at = now();
            $config->save();
        });
    }

    private function deleteAvatarIfStored(string $avatar_url): void
    {
        if ($avatar_url === '') {
            return;
        }

        $storage_url = rtrim((string) config('app.storage_url'), '/');

        if ($storage_url !== '' && str_starts_with($avatar_url, $storage_url)) {
            $path = ltrim(substr($avatar_url, strlen($storage_url)), '/');
            FileService::delete($path);
        }
    }
}
