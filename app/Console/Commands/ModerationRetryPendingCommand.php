<?php

namespace App\Console\Commands;

use App\Jobs\ModerateEventAssetJob;
use App\Models\EventAsset;
use App\Services\Enums\EventAssetTypeEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Console\Command;

class ModerationRetryPendingCommand extends Command
{
    protected $signature = 'moderation:retry-pending';

    protected $description = 'Re-dispatch moderation jobs for image assets stuck in PENDING status';

    public function handle(): int
    {
        $assetIds = EventAsset::where('status', StatusEnum::PENDING)
            ->where('asset_type', EventAssetTypeEnum::IMAGE_ID)
            ->pluck('id');

        if ($assetIds->isEmpty()) {
            $this->info('No pending image assets found.');
            return self::SUCCESS;
        }

        foreach ($assetIds as $id) {
            try {
                ModerateEventAssetJob::dispatch($id);
                $this->line("Dispatched moderation job for asset {$id}");
            } catch (\Throwable $e) {
                $this->error("Failed moderation for asset {$id}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('With QUEUE_CONNECTION=sync (local), jobs run immediately.');
        $this->info('With database (production), ensure php artisan queue:work is running.');

        return self::SUCCESS;
    }
}
