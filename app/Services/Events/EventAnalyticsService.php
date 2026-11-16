<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventAnalyticsEvent;
use App\Models\EventAsset;
use App\Services\Enums\EventAssetTypeEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Users\UserService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventAnalyticsService
{
    public const NAME_LANDING_VIEW = 'landing_view';
    public const NAME_UPLOAD_PAGE_VIEW = 'upload_page_view';
    public const NAME_GALLERY_VIEW = 'gallery_view';
    public const NAME_GALLERY_DOWNLOAD = 'gallery_download';

    public const ALLOWED_NAMES = [
        self::NAME_LANDING_VIEW,
        self::NAME_UPLOAD_PAGE_VIEW,
        self::NAME_GALLERY_VIEW,
        self::NAME_GALLERY_DOWNLOAD,
    ];

    public function __construct(
        private ?UserService $user_service = null,
    ) {
        $this->user_service ??= new UserService();
    }

    public function track(string $event_path, array $payload, Request $request): array
    {
        $event = Event::where('path', $event_path)->first();
        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        $device_id = (string) $payload['device_id'];
        $rows = [];
        $now = now();
        $ip = (string) ($request->ip() ?? '');
        $ua = substr((string) ($request->userAgent() ?? ''), 0, 255);

        foreach ($payload['events'] as $item) {
            $name = (string) ($item['name'] ?? '');
            if (!in_array($name, self::ALLOWED_NAMES, true)) {
                continue;
            }

            $meta = null;
            if (isset($item['meta']) && is_array($item['meta'])) {
                $meta = [];
                if (isset($item['meta']['count'])) {
                    $meta['count'] = max(1, min(500, (int) $item['meta']['count']));
                }
                if ($meta === []) {
                    $meta = null;
                }
            }

            $rows[] = [
                'event_id' => $event->id,
                'device_id' => $device_id,
                'name' => $name,
                'meta' => $meta ? json_encode($meta) : null,
                'ip' => $ip !== '' ? $ip : null,
                'user_agent' => $ua !== '' ? $ua : null,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            EventAnalyticsEvent::insert($rows);
        }

        return ['recorded' => count($rows)];
    }

    public function overview(int $event_id, int $user_id): array
    {
        $event = Event::find($event_id);
        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND);
        }

        if (!$this->isAuthorizedToAccessEvent($event, $user_id)) {
            throw new Exception(MessagesEnum::EVENT_NOT_AUTHORIZED);
        }

        $landing_visitors = $this->uniqueDevicesForName($event_id, self::NAME_LANDING_VIEW);
        $upload_page_visitors = $this->uniqueDevicesForName($event_id, self::NAME_UPLOAD_PAGE_VIEW);
        $gallery_visitors = $this->uniqueDevicesForName($event_id, self::NAME_GALLERY_VIEW);
        $uploaders = $this->uniqueUploaders($event_id);

        $total_guest_uploads = EventAsset::where('event_id', $event_id)
            ->where('created_by_guest', true)
            ->count();
        $photos = EventAsset::where('event_id', $event_id)
            ->where('created_by_guest', true)
            ->where('asset_type', EventAssetTypeEnum::IMAGE_ID)
            ->count();
        $videos = EventAsset::where('event_id', $event_id)
            ->where('created_by_guest', true)
            ->where('asset_type', EventAssetTypeEnum::VIDEO_ID)
            ->count();

        $avg_uploads_per_uploader = $uploaders > 0
            ? round($total_guest_uploads / $uploaders, 2)
            : 0.0;

        $pending = EventAsset::where('event_id', $event_id)
            ->where('status', StatusEnum::PENDING)
            ->count();
        $approved = EventAsset::where('event_id', $event_id)
            ->where('status', StatusEnum::ACTIVE)
            ->count();
        $blocked = EventAsset::where('event_id', $event_id)
            ->where('status', StatusEnum::BLOCKED)
            ->count();

        $peak_hour_row = EventAsset::where('event_id', $event_id)
            ->where('created_by_guest', true)
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as c'))
            ->groupBy(DB::raw('HOUR(created_at)'))
            ->orderByDesc('c')
            ->first();

        $download_events = EventAnalyticsEvent::where('event_id', $event_id)
            ->where('name', self::NAME_GALLERY_DOWNLOAD)
            ->get(['meta']);

        $gallery_download_events = $download_events->count();
        $gallery_download_items = $download_events->sum(function (EventAnalyticsEvent $row) {
            $count = is_array($row->meta) ? ($row->meta['count'] ?? 1) : 1;
            return max(1, (int) $count);
        });

        $active_visitors_15m = (int) EventAnalyticsEvent::where('event_id', $event_id)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count(DB::raw('DISTINCT device_id'));

        return [
            'landing_visitors' => $landing_visitors,
            'upload_page_visitors' => $upload_page_visitors,
            'uploaders' => $uploaders,
            'gallery_visitors' => $gallery_visitors,
            'rates' => [
                'landing_to_upload_page' => $this->rate($upload_page_visitors, $landing_visitors),
                'upload_page_to_upload' => $this->rate($uploaders, $upload_page_visitors),
                'upload_to_gallery' => $this->rate($gallery_visitors, $uploaders),
            ],
            'total_guest_uploads' => $total_guest_uploads,
            'avg_uploads_per_uploader' => $avg_uploads_per_uploader,
            'photos' => $photos,
            'videos' => $videos,
            'pending' => $pending,
            'approved' => $approved,
            'blocked' => $blocked,
            'peak_upload_hour' => $peak_hour_row ? (int) $peak_hour_row->hour : null,
            'gallery_download_events' => $gallery_download_events,
            'gallery_download_items' => (int) $gallery_download_items,
            'active_visitors_15m' => $active_visitors_15m,
        ];
    }

    private function uniqueDevicesForName(int $event_id, string $name): int
    {
        return (int) EventAnalyticsEvent::where('event_id', $event_id)
            ->where('name', $name)
            ->count(DB::raw('DISTINCT device_id'));
    }

    private function uniqueUploaders(int $event_id): int
    {
        $with_device = (int) EventAsset::where('event_id', $event_id)
            ->where('created_by_guest', true)
            ->whereNotNull('device_id')
            ->count(DB::raw('DISTINCT device_id'));

        $without_device = (int) EventAsset::where('event_id', $event_id)
            ->where('created_by_guest', true)
            ->whereNull('device_id')
            ->select(DB::raw("COUNT(DISTINCT CONCAT(COALESCE(ip,''), '|', COALESCE(user_agent,''))) as c"))
            ->value('c');

        return $with_device + $without_device;
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 1);
    }

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
}
