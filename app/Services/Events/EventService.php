<?php

namespace App\Services\Events;

use App\Models\EventConfig;
use App\Services\ActionGates\EventActionsGate;
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
use App\Services\Helpers\FileService;
use App\Services\Helpers\MailService;
use App\Services\Orders\StoreService;
use App\Services\Helpers\TokenService;
use App\Http\Requests\UploadFileRequest;
use App\Models\User;
use App\Services\Enums\EventAssetTypeEnum;
use App\Services\Enums\EventGalleryTypeEnum;
use App\Jobs\ModerateEventAssetJob;
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
            ->whereIn('status', [StatusEnum::ACTIVE, StatusEnum::READY, StatusEnum::PENDING, StatusEnum::IN_PROGRESS])
            ->with('config:id,event_id,preview_site_display_image,preview_site_display_name,preview_site_display_date,preview_guests_assets_in_gallery,preview_owners_assets_in_gallery,video_upload_enabled,qr_card_design,qr_card_text')
            ->first();
    }

    /**
     * @param string $event_path
     * @return ?Event
     */
    public function getBaseGallery(string $event_path): ?Event
    {
        return Event::where('path', $event_path)
            ->select('id', 'image', 'name', 'starts_at', 'user_id', 'status')
            ->whereIn('status', [StatusEnum::ACTIVE, StatusEnum::READY, StatusEnum::PENDING, StatusEnum::IN_PROGRESS])
            ->with(
                'displayedAssets:id,event_id,asset_type,path', 
                'config:id,event_id,preview_site_display_image,preview_site_display_name,preview_site_display_date,preview_guests_assets_in_gallery,preview_owners_assets_in_gallery,video_upload_enabled'
            )
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
            ->with('assets:id,event_id,asset_type,path,is_displayed',
                'activeDownloadProcess:id,path,status,event_id',
                'config:id,event_id,preview_site_display_image,preview_site_display_name,preview_site_display_date,preview_guests_assets_in_gallery,preview_owners_assets_in_gallery,preview_qr_in_gallery,displayed_gallery,video_upload_enabled,qr_card_design,qr_card_text')
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
    public function getEventAssets(int $id, int $user_id, bool $includeBlocked = true): array
    {
        if (!$event = Event::find($id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $statuses = [StatusEnum::ACTIVE, StatusEnum::PENDING];
        if ($includeBlocked) {
            $statuses[] = StatusEnum::BLOCKED;
        }

        $assets = EventAsset::where('event_id', $id)
            ->whereIn('status', $statuses)
            ->select('id', 'event_id', 'asset_type', 'path', 'is_displayed', 'status', 'moderation_labels')
            ->get();

        $blockedCount = EventAsset::where('event_id', $id)
            ->where('status', StatusEnum::BLOCKED)
            ->count();

        return [
            'assets' => $assets,
            'blocked_count' => $blockedCount,
        ];
    }
    
    /**
     * @param string $token
     * @return Event
    */
    public function getEventByToken(string $token): ?Event
    {
        return Event::whereIn('status', [StatusEnum::ACTIVE, StatusEnum::IN_PROGRESS, StatusEnum::READY])
                      ->where('path', $token)
                      ->first();
    }
    
    /**
     * @param int $id
     * @param int $user_id
     * @return Collection
     */
    public function getEventAssetsForGallery(int $id, int $user_id): Collection
    {
        if (!$event = Event::find($id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $event->load('config');

        
        $query = EventAsset::where('event_id', $id)
            ->visibleInGallery();
        
        if(!$event->config->preview_guests_assets_in_gallery || !$event->config->preview_owners_assets_in_gallery) {
            $query = $query->where('created_by_guest', boolval($event->config->preview_guests_assets_in_gallery));
        }
        
        return $query->select('id', 'event_id', 'asset_type', 'path')
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
                FileService::delete($event_asset->path);
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
     * @return bool
     */
    public function hideEventAssets(int $id, array $data, int $user_id): bool
    {
        if (!$event = Event::find($id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }
            
        foreach($data['assets'] as $event_asset_id) {
            $event_asset = EventAsset::find($event_asset_id);
            $event_asset->is_displayed = !$event_asset->is_displayed;
            $event_asset->save();
        }
        
        return true;
    }

    /**
     * @param int $id
     * @param array $data
     * @param int $user_id
     * @return bool
     */
    public function blockEventAssets(int $id, array $data, int $user_id): bool
    {
        if (!$event = Event::find($id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $blockableCount = EventAsset::whereIn('id', $data['assets'])
            ->where('event_id', $id)
            ->whereIn('status', [StatusEnum::ACTIVE, StatusEnum::PENDING])
            ->count();

        if ($blockableCount !== count($data['assets'])) {
            throw new Exception(MessagesEnum::EVENTS_ASSETS_NOT_FOUND);
        }

        EventAsset::whereIn('id', $data['assets'])
            ->where('event_id', $id)
            ->whereIn('status', [StatusEnum::ACTIVE, StatusEnum::PENDING])
            ->update([
                'status' => StatusEnum::BLOCKED,
                'is_displayed' => false,
                'moderation_labels' => [
                    'reasons' => ['Manually blocked'],
                    'labels' => [],
                ],
            ]);

        return true;
    }

    /**
     * @param int $id
     * @param array $data
     * @param int $user_id
     * @return bool
     */
    public function unblockEventAssets(int $id, array $data, int $user_id): bool
    {
        if (!$event = Event::find($id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $blockedCount = EventAsset::whereIn('id', $data['assets'])
            ->where('event_id', $id)
            ->where('status', StatusEnum::BLOCKED)
            ->count();

        if ($blockedCount !== count($data['assets'])) {
            throw new Exception(MessagesEnum::EVENTS_ASSETS_NOT_FOUND);
        }

        EventAsset::whereIn('id', $data['assets'])
            ->where('event_id', $id)
            ->where('status', StatusEnum::BLOCKED)
            ->update([
                'status' => StatusEnum::ACTIVE,
                'is_displayed' => true,
                'moderation_labels' => null,
            ]);

        return true;
    }

    public function updateGallerySettings(int $event_id, array $data, int $user_id): ?EventConfig
    {
        if (!$event = Event::find($event_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $event_config = EventConfig::where('event_id', $event_id)->first();
        if (!$event_config) {
            throw new Exception(MessagesEnum::EVENT_CONFIG_NOT_FOUND);
        }

        $event_config->displayed_gallery = $data['selectedAlbum'];
        $event_config->save();

        return $event_config;
    }

    public function updateQrCardSettings(int $event_id, array $data, int $user_id): ?EventConfig
    {
        if (!$event = Event::find($event_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $event_config = EventConfig::where('event_id', $event_id)->first();
        if (!$event_config) {
            throw new Exception(MessagesEnum::EVENT_CONFIG_NOT_FOUND);
        }

        if (array_key_exists('design', $data)) {
            $event_config->qr_card_design = $data['design'];
        }

        if (array_key_exists('text', $data)) {
            $event_config->qr_card_text = $data['text'];
        }

        $event_config->save();

        return $event_config;
    }

    /**
     * @param int $id
     * @param array $data
     * @param int $user_id
     * @return ?array
     */
    public function downloadEventAssets(int $id, array $data, int $user_id): ?array
    {
        LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $id, 'step' => 1]);
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
        LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $id, 'step' => 2]);

        if (!$download_job->canStartNewProcess($event)) {
            throw new Exception(MessagesEnum::FAILED_TO_START_DOWNLOAD_PROCESS);
        }
        
        LogService::init()->info(MessagesEnum::EVENT_DOWNLOAD_PROCESS, ['event_id' => $id, 'step' => 3]);

        return $download_job->zip()->only(['id', 'event_id', 'status', 'path']) ?? null;
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

        $this->createEventConfig($event->id);
        return $event;
    }

    /**
     * @param Order $order
     * @param User $user
     * @return ?Event
     */
    public function createDemo(Order $order, User $user): ?Event
    {
        $event = new Event;
        $event->name = 'האירוע של ' . $user->first_name;
        $event->order_id = $order->id;
        $event->path = TokenService::generate(12);
        $event->user_id = $order->user_id;
        $event->status = StatusEnum::ACTIVE;
        $event->starts_at = now()->format('Y-m-d H:i:s');
        $event->finished_at = now()->addHours(1)->format('Y-m-d H:i:s');
        $event->save();

        $this->createEventConfig($event->id);
        return $event;
    }

    /**
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

        $this->createEventConfig($event->id);
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
            $event->image = FileService::create($data['image'], "events/$event_id");
        }

        if ($event->isPending()) {
            $event->starts_at = $this->getEventStartTime($data['starts_at'] ?? '') ?? $event->starts_at;
            if (!empty($data['starts_at'])) {
                $event->finished_at = $this->getEventFinishTime($event->starts_at);
            }
        }

        $event->save();

        $this->updateEventConfig($event_id, $data['config'] ?? []);
        return $event->load('config');
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
        $event->image = FileService::create($data['image'], "events/$event_id");
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

        EventActionsGate::canUpdateEventStatus($event, $status);
        
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
        $this->deleteEventsDownloadProcesses($event_id);
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

        $asset_type = $this->getFileType($request);
        if ($asset_type === EventAssetTypeEnum::VIDEO_ID) {
            $event->loadMissing('config');
            if (!$event->config?->video_upload_enabled) {
                throw new Exception(MessagesEnum::EVENT_VIDEO_UPLOAD_DISABLED);
            }
        }

        $event_asset = new EventAsset;
        $event_asset->path = FileService::create($request->file('file'), "events/$event_id/gallery");
        $event_asset->event_id = $event_id;
        $event_asset->is_displayed = true;
        $event_asset->asset_type = $asset_type;
        $event_asset->user_agent = $request->userAgent();
        $event_asset->ip = $request->ip();
        $event_asset->created_by_guest = !boolval($user_id);

        if ($event_asset->asset_type === EventAssetTypeEnum::IMAGE_ID && config('moderation.enabled')) {
            $event_asset->status = StatusEnum::PENDING;
        } else {
            $event_asset->status = StatusEnum::ACTIVE;
        }

        $event_asset->save();

        if ($event_asset->asset_type === EventAssetTypeEnum::IMAGE_ID && config('moderation.enabled')) {
            ModerateEventAssetJob::dispatch($event_asset->id);
        }

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
        $is_deleted = FileService::delete("events/$event_id");
        if(!$is_deleted) {
            LogService::init()->error(MessagesEnum::FAILED_TO_DELETE_EVENT_ASSETS_FOLDER, ['id' => $event_id]);
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
     * @param int $event_id
     * @param int $user_id
     * @return Event
     * @throws Exception
     */
    public function assertEventAccess(int $event_id, int $user_id): Event
    {
        $event = Event::find($event_id);

        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        return $event;
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
        $has_files_space = $order->subscription->files_allowed === 0 || $this->getEventTotalAssets($event->id) < $order->subscription->files_allowed;
        
        // No Space
        if(!$has_files_space) {
            logService::init()->info('Event files limit reached', ['event_id' => $event->id]);
            return false;
        }

        // Post Event - No one
        if($event->isInactive() || $event->isActive()) {
            LogService::init()->info('Not Authorized to upload file - Event Inactive or Finished', ['user_id' => $user_id, 'event_id' => $event->id, 'status' => $event->status]);
            return false;
        }
        
        // Pre Event - Owner Only
        if($event->isPending() || $event->isReady()) {
            if($user_id === $event->user_id) {
                return true;
            }

            LogService::init()->info('Not Authorized to upload file - Event isPending or Ready', ['user_id' => $user_id, 'event_id' => $event->id, 'status' => $event->status]);
            return false;
        }
        
        // During Event - All
        if($event->isInProgress()) {
            return true;
        } 
        
        LogService::init()->info('Not Authorized to upload file, last validation', ['user_id' => $user_id, 'event_id' => $event->id, 'status' => $event->status]);
        return false;
    }

    /**
     * @param int $event_id
     * @return int
     */
    private function getEventTotalAssets(int $event_id): int
    {
        return EventAsset::where('event_id', $event_id)
            ->whereIn('status', [StatusEnum::ACTIVE, StatusEnum::PENDING])
            ->count();
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

    private function createEventConfig(int $event_id): void
    {
        EventConfig::firstOrCreate(
            ['event_id' => $event_id],
            $this->defaultEventConfigAttributes()
        );
    }

    private function updateEventConfig(int $event_id, array $config): void
    {
        $event_config = EventConfig::firstOrNew(
            ['event_id' => $event_id],
            $this->defaultEventConfigAttributes()
        );

        foreach ($this->booleanEventConfigKeys() as $key) {
            if (array_key_exists($key, $config) && $config[$key] !== null) {
                $event_config->{$key} = $config[$key] === 'true';
            }
        }

        $event_config->save();
    }

    private function defaultEventConfigAttributes(): array
    {
        return [
            'preview_site_display_image'        => true,
            'preview_site_display_name'         => true,
            'preview_site_display_date'         => true,
            'preview_guests_assets_in_gallery'  => true,
            'preview_owners_assets_in_gallery'  => true,
            'preview_qr_in_gallery'             => true,
            'displayed_gallery'                 => EventGalleryTypeEnum::SINGLE_GALLERY,
            'video_upload_enabled'              => true,
        ];
    }

    private function booleanEventConfigKeys(): array
    {
        return [
            'preview_site_display_image',
            'preview_site_display_name',
            'preview_site_display_date',
            'preview_guests_assets_in_gallery',
            'preview_owners_assets_in_gallery',
            'preview_qr_in_gallery',
            'video_upload_enabled',
        ];
    }
}
