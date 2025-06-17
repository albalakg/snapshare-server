<?php

namespace App\Services\Events;

use Exception;
use App\Models\Event;
use App\Models\EventAsset;
use App\Models\EventAssetDownload;
use App\Services\Enums\StatusEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Helpers\MailService;
use App\Jobs\ZipEventAssetsForDownloadJob;
use App\Services\Helpers\LogService;

class ZipEventAssetsForDownload
{
    public function __construct(
        private Event $event,
        private array $asset_ids,
        private int $created_by,
        protected ?MailService $mail_service = null,
    )
    {}

    public function canStartNewProcess(Event $event): bool
    {
        $no_active_process = !EventAssetDownload::where('event_id', $event->id)
                                ->whereIn('status', [StatusEnum::PENDING, StatusEnum::IN_PROGRESS])
                                ->exists();

        LogService::init()->info('canStartNewProcess', ['no_active_process' => $no_active_process]);

        $valid_amount_of_processes = EventAssetDownload::where('event_id', $event->id)
                                ->where('status', '!=', StatusEnum::INACTIVE)
                                ->count() < 50;
        LogService::init()->info('canStartNewProcess 2', ['valid_amount_of_processes' => $valid_amount_of_processes]);

        return $no_active_process && $valid_amount_of_processes;
    }

    /**
     * @return ?EventAssetDownload
    */
    public function zip(): ?EventAssetDownload
    {
        // Validate assets exist and belong to the event
        $total_assets = EventAsset::whereIn('id', $this->asset_ids)
            ->where('event_id', $this->event->id)
            ->count();

        if ($total_assets !== count($this->asset_ids)) {
            throw new Exception(MessagesEnum::EVENTS_ASSETS_NOT_FOUND);
        }

        LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $this->event->id, 'step' => 4]);

        try {
            // Create download record
            $download = EventAssetDownload::create([
                'event_id' => $this->event->id,
                'event_assets' => json_encode($this->asset_ids),
                'status' => StatusEnum::PENDING,
                'created_by' => $this->created_by
            ]);
            
            // Dispatch job
            ZipEventAssetsForDownloadJob::dispatch($download, $this->mail_service);

            LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $this->event->id, 'step' => 5]);
            return $download;
        } catch (\Exception $e) {
            return null;
        }
    }
}
