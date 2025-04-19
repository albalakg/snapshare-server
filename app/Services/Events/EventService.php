<?php

namespace App\Services\Events;

use DateTime;
use Exception;
use ZipArchive;
use App\Models\Event;
use App\Models\Order;
use App\Models\EventAsset;
use App\Services\Enums\LogsEnum;
use App\Models\EventAssetDownload;
use App\Services\Enums\StatusEnum;
use App\Services\Users\UserService;
use App\Services\Enums\MessagesEnum;
use App\Services\Helpers\LogService;
use Illuminate\Support\Facades\Http;
use App\Services\Helpers\FileService;
use App\Services\Helpers\MailService;
use App\Services\Orders\StoreService;
use App\Services\Helpers\TokenService;
use App\Http\Requests\UploadFileRequest;
use App\Services\Enums\EventAssetTypeEnum;
use Illuminate\Database\Eloquent\Collection;

/**
 * Event Life Cycle:
 * Pending -> Ready -> In Progress -> Active -> Inactive
 * 
 * 1. Creates automatically with pending status while creating an order
 * 2. User moves the status for ready before the start date
 * 3. When the event starts the status turns to active via a job
 * 4. After 30 days the event becomes disabled and all assets are removed
 */
class EventService
{
    public function __construct(
        private ?UserService $user_service = null,
        private ?StoreService $order_service = null,
        private ?MailService $mail_service = null,
    ) {}

    /**
     * @return Collection
     */
    public function get(): Collection
    {
        return Event::with('user')->get();
    }

    /**
     * @param string $event_path
     * @return ?Event
     */
    public function getBaseInfo(string $event_path): ?Event
    {
        return Event::where('path', $event_path)
            ->select('id', 'image', 'name', 'starts_at', 'user_id', 'status')
            ->where('status', [StatusEnum::ACTIVE, StatusEnum::READY, StatusEnum::PENDING])
            ->first();
    }

    /**
     * @param int $id
     * @return ?Event
     */
    public function find(int $id): ?Event
    {
        $event = Event::with('assets')->first($id);
        if (!$event) {
            return null;
        }

        if (!$event->isActive()) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        return $event;
    }

    /**
     * @param int $user_id
     * @return ?Event
     */
    public function getEventByUser(int $user_id): ?Event
    {
        return Event::where('user_id', $user_id)
            ->where('status', '!=', StatusEnum::INACTIVE)
            ->select('id', 'order_id', 'path', 'image', 'name', 'status', 'starts_at', 'finished_at')
            ->with('assets:id,event_id,asset_type,path', 'activeDownloadProcess:id,path,status,event_id')
            ->first();
    }

    /**
     * Get active download process for an event
     * 
     * @param int $event_id
     * @param int $user_id
     * @return ?EventAssetDownload
     */
    public function getActiveDownloadProcess(int $event_id, int $user_id): ?EventAssetDownload
    {
        if (!$event = Event::find($event_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        return EventAssetDownload::where('event_id', $event_id)
            ->where('status', '!=', StatusEnum::INACTIVE)
            ->select('id', 'path', 'status', 'event_id')
            ->latest()
            ->first();
    }

    /**
     * @param int $id
     * @param int $user_id
     * @return Collection
     */
    public function getEventAssets(int $id, int $user_id): Collection
    {
        if (!$event = Event::find($id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        return EventAsset::where('event_id', $id)
            ->select('id', 'event_id', 'asset_type', 'path')
            ->get();
    }

    /**
     * @param int $id
     * @param array $data
     * @param int $user_id
     * @return bool
     */
    public function deleteEventAssets(int $id, array $data, int $user_id)
    {
        if (!$event = Event::find($id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $event_assets = EventAsset::whereIn('id', $data['assets'])
            ->select('id', 'event_id', 'path')
            ->get();

        foreach ($event_assets as $event_asset) {
            try {
                FileService::delete($event_asset->path, FileService::S3_DISK);
            } catch (Exception $ex) {
                LogService::init()->error($ex, ['error' => LogsEnum::FAILED_TO_DELETE_EVENT_ASSET]);
            }
        }

        return EventAsset::whereIn('id', $data['assets'])->delete();
    }

    /**
     * @param int $id
     * @param array $data
     * @param int $user_id
     * @return ?array
     */
    public function downloadEventAssets(int $id, array $data, int $user_id): ?array
    {
        if (!$event = Event::find($id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $download_job = new ZipEventAssetsForDownload(
            $event, 
            $data['assets'], 
            $user_id,
            $this->mail_service
        );
        if ($download_job->canStartNewProcess($event)) {
            return $download_job->zip()->only(['id', 'event_id', 'status', 'path']) ?? null;
        }

        return null;
    }

    /**
     * Created automatically with the order
     * 
     * @param Order $order
     * @return ?Event
     */
    public function create(Order $order): ?Event
    {
        $event = new Event;
        $event->order_id = $order->id;
        $event->path = TokenService::generate(12);
        $event->user_id = $order->user_id;
        $event->status = StatusEnum::PENDING;
        $event->save();
        return $event;
    }

    /**
     * 
     * @param array $data
     * @return ?Event
     */
    public function createByAdmin(array $data): ?Event
    {
        $event = new Event;
        $event->status = $data['status'] ?? StatusEnum::PENDING;
        $event->order_id = $data['order_id'] ?? null;
        $event->user_id = $data['user_id'];
        $event->name = $data['name'];
        $event->description = $data['description'];
        $event->starts_at = $data['starts_at'];
        if (!empty($data['starts_at'])) {
            $event->finished_at = $this->getEventFinishTime($event->starts_at);
        }
        $event->save();
        return $event;
    }

    /**
     * @param int $event_id
     * @param array $data
     * @param int $user_id
     * @return ?Event
     */
    public function update(int $event_id, array $data, int $user_id): ?Event
    {
        $event = Event::find($event_id);
        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $event->name = $data['name'] ?? $event->name;
        if ($data['image']) {
            $event->image = FileService::create($data['image'], "events/$event_id", FileService::S3_DISK);
        }
        // $event->description = $data['description'] ?? $event->description;

        if ($event->isPending()) {
            $event->starts_at = $this->getEventStartTime($data['starts_at'] ?? '') ?? $event->starts_at;
            if (!empty($data['starts_at'])) {
                $event->finished_at = $this->getEventFinishTime($event->starts_at);
            }
        }

        $event->save();
        return $event;
    }

    /**
     * @param int $event_id
     * @param array $data
     * @return ?Event
     */
    public function updateByAdmin(int $event_id, array $data): ?Event
    {
        $event = Event::find($event_id);
        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        $event->name = $data['name'] ?? $event->name;
        $event->image = FileService::create($data['image'], "events/$event_id", FileService::S3_DISK);
        // $event->description = $data['description'] ?? $event->description;
        $event->starts_at = $this->getEventStartTime($data['start_at'] ?? '');
        $event->status = $data['status'] ?? $event->status;
        if (!empty($data['starts_at'])) {
            $event->finished_at = $this->getEventFinishTime($event->starts_at);
        }

        $event->save();
        return $event;
    }

    /**
     * @param int $status
     * @param int $event_id
     * @return bool
     */
    public function updateStatus(int $status, int $event_id): bool
    {
        if (!$event = Event::find($event_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }
        return $event->update(['status' => $status]);
    }

    /**
     * @param int $event_id
     * @param ?int $user_id
     * @return bool
     */
    public function disable(int $event_id, ?int $user_id = null): bool
    {
        $event = Event::find($event_id);
        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if($user_id) {
            if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
                throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
            }
        }

        $this->deleteEventsAssetsByEvent($event_id);
        return $this->updateStatus(StatusEnum::INACTIVE, $event->id);
    }

    /**
     * @param int $event_id
     * @param int $user_id
     * @return bool
     */
    public function delete(int $event_id, int $user_id): bool
    {
        $event = Event::find($event_id);
        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $this->deleteEventsAssetsByEvent($event_id);
        $this->deleteEventsDownloadProcesses($event_id);
        return $event->delete();
    }

    /**
     * @param int $event_id
     * @param UploadFileRequest $request
     * @param ?int $user_id
     * @return EventAsset
     */
    public function uploadFile(int $event_id, UploadFileRequest $request, ?int $user_id = null): EventAsset
    {
        $event = Event::find($event_id);
        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToUploadAsset($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $event_asset = new EventAsset;
        $event_asset->path = FileService::create($request['file'], "events/$event_id/gallery", FileService::S3_DISK);
        $event_asset->event_id = $request->event_id;
        $event_asset->asset_type = $this->getFileType($request);
        $event_asset->user_agent = $request->userAgent();
        $event_asset->ip = $request->ip();
        $event_asset->status = StatusEnum::ACTIVE;
        $event_asset->save();

        return $event_asset;
    }

    /**
     * @param string $starts_at
     * @return ?string
     */
    private function getEventStartTime(string $starts_at): ?string
    {
        $date = new DateTime($starts_at) ?? '';
        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    /**
     * @param string $starts_at
     * @return string
     */
    private function getEventFinishTime(string $starts_at): string
    {
        $date = new DateTime($starts_at);
        $date->add(new \DateInterval('P1D'));
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Deletes the records from the DB and removes the entire folder of the event in S3
     * @param int $event_id
     * @return void
     */
    private function deleteEventsAssetsByEvent(int $event_id): void
    {
        $event_assets = EventAsset::where('event_id', $event_id)
            ->select('id', 'path')
            ->get();

        foreach ($event_assets as $event_asset) {
            try {
                FileService::delete("events/$event_id", FileService::S3_DISK);
            } catch (Exception $ex) {
                LogService::init()->error($ex, ['message' => MessagesEnum::FAILED_TO_DELETE_EVENT_ASSETS_FOLDER]);
            }
        }

        EventAsset::where('event_id', $event_id)->delete();
    }

    /**
     * @param int $event_id
     * @return void
     */
    private function deleteEventsDownloadProcesses(int $event_id): void
    {
        EventAssetDownload::where('event_id', $event_id)->delete();
    }

    /**
     * @param Event $event
     * @param int $user_id
     * @return bool
     */
    private function isAuthorizedToAccessEvent(Event $event, int $user_id): bool
    {
        if ($this->user_service->find($user_id)->isAdmin()) {
            return true;
        }

        if (!$event->isInactive() && $event->user_id === $user_id) {
            return true;
        }

        return false;
    }

    /**
     * @param Event $event
     * @param ?int $user_id
     * @return bool
     */
    private function isAuthorizedToUploadAsset(Event $event, ?int $user_id): bool
    {
        $order = $this->order_service->find($event->order_id);
        $has_files_space = $this->getEventTotalAssets($event->id) < $order->subscription->files_allowed;
        
        if(!$has_files_space) {
            return false;
        }
        
        LogService::init()->info('test', ['user_id' => $user_id, 'event_id' => $event->user_id, 'is' => $event->isInactive()]);
        if(($user_id && $user_id === $event->user_id) && !$event->isInactive()) {
            return true;
        }

        if($event->isInProgress()) {
            return true;
        } 

        return false;
    }

    /**
     * @param int $event_id
     * @return int
     */
    private function getEventTotalAssets(int $event_id): int
    {
        return EventAsset::where('event_id', $event_id)->count();
    }

    /**
     * @param UploadFileRequest $request
     * @return int
     */
    private function getFileType(UploadFileRequest $request): int
    {
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        return in_array($extension, ['mp4', 'mov', 'avi']) ? EventAssetTypeEnum::VIDEO_ID : EventAssetTypeEnum::IMAGE_ID;
    }
}
