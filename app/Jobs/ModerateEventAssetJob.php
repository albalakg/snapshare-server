<?php

namespace App\Jobs;

use App\Models\EventAsset;
use App\Services\Enums\EventAssetTypeEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Helpers\LogService;
use App\Services\Moderation\ContentModerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ModerateEventAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        protected int $eventAssetId,
    ) {}

    public function handle(ContentModerationService $moderationService): void
    {
        LogService::init()->info('Moderation job started', [
            'event_asset_id' => $this->eventAssetId,
        ]);

        $asset = EventAsset::find($this->eventAssetId);

        if (!$asset || $asset->status !== StatusEnum::PENDING) {
            LogService::init()->info('Moderation job skipped', [
                'event_asset_id' => $this->eventAssetId,
                'status' => $asset?->status,
            ]);
            return;
        }

        if ($asset->asset_type !== EventAssetTypeEnum::IMAGE_ID) {
            $asset->update(['status' => StatusEnum::ACTIVE]);
            return;
        }

        try {
            $result = $moderationService->checkImage($asset->path);
            LogService::init()->info('Moderation result', ['result' => $result, 'event_asset_id' => $this->eventAssetId]);
        } catch (Throwable $e) {
            LogService::init()->error($e, [
                'event_asset_id' => $this->eventAssetId,
                'path' => $asset->path,
            ]);
            throw $e;
        }

        if ($result['isValid']) {
            $asset->update([
                'status' => StatusEnum::ACTIVE,
                'moderation_labels' => null,
            ]);
            return;
        }

        $asset->update([
            'status' => StatusEnum::BLOCKED,
            'is_displayed' => false,
            'moderation_labels' => [
                'reasons' => $result['reasons'],
                'labels' => $result['labels'],
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $asset = EventAsset::find($this->eventAssetId);

        if ($asset && $asset->status !== StatusEnum::PENDING) {
            return;
        }

        LogService::init()->error($exception, [
            'event_asset_id' => $this->eventAssetId,
            'message' => 'Content moderation job failed after all retries; asset remains pending',
        ]);
    }
}
