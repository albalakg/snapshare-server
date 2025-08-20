<?php

namespace App\Jobs;

use Exception;
use ZipStream\ZipStream;
use App\Models\EventAsset;
use Illuminate\Bus\Queueable;
use App\Services\Enums\MailEnum;
use App\Models\EventAssetDownload;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Helpers\FileService;
use App\Services\Helpers\LogService;
use App\Services\Helpers\MailService;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ZipEventAssetsForDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    /**
     * Create a new job instance.
     */
    public function __construct(
        protected EventAssetDownload $event_asset_download,
        protected ?MailService $mail_service = null,
    )
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $this->event_asset_download->event_id, 'step' => 6]);
            $event_assets_ids = json_decode($this->event_asset_download->event_assets);
            $this->event_asset_download->update(['status' => StatusEnum::IN_PROGRESS]);
            $event_assets = EventAsset::whereIn('id', $event_assets_ids)->get();
            LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $this->event_asset_download->event_id, 'step' => 7]);
    
            $zip_file_name = 'event_assets_' . $this->event_asset_download->event_id . '_' . time() . '.zip';
            $s3_zip_path = 'events/' . $this->event_asset_download->event_id . '/downloads/' . $zip_file_name;
    
            // Create a stream that will be written to S3
            $stream = fopen('php://temp', 'r+b');
    
            // Create ZipStream instance
            $zip = new ZipStream(outputStream: $stream);
            LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $this->event_asset_download->event_id, 'step' => 8]);

            foreach ($event_assets as $event_asset) {
                try {
                    if ($file_data = FileService::get($event_asset->path)) {
                        $zip->addFile(
                            fileName: basename($event_asset->path),
                            data: $file_data
                        );
                    }
                } catch (Exception $e) {
                    // 
                }
            }
    
            $zip->finish();
            rewind($stream);
    
            FileService::createFileWithPut($stream, $s3_zip_path);

            fclose($stream);
            LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $this->event_asset_download->event_id, 'step' => 9]);

            $this->event_asset_download->update([
                'status' => StatusEnum::ACTIVE,
                'path' => $s3_zip_path
            ]);

            $data = [
                'event' => $this->event_asset_download->event->loadCount("assets"),
                'first_name' => $this->event_asset_download->event->user->first_name ?? '',
                'download_url' => config('app.client_url') . "/event/assets",
            ];

            // LogService::init()->info('assets_count', ['event' => $data['event']]);
            $this->mail_service->send($this->event_asset_download->event->user->email, MailEnum::ASSETS_READY_FOR_DOWNLOAD, $data);
            LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $this->event_asset_download->event_id, 'step' => 10, 'assets_count' => $data['event']]);
        } catch (\Exception $e) {
            $this->event_asset_download->update(['status' => StatusEnum::INACTIVE]);
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
