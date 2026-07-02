<?php

namespace App\Services\EventHub;

use App\Models\Event;
use App\Models\EventHub;
use App\Services\Enums\HubBlockTypeEnum;
use App\Services\Enums\IcebreakerStatusEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Events\EventService;
use App\Services\Users\UserService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class EventHubService
{
    public function __construct(
        private ?EventService $event_service = null,
        private ?HubBlockValidator $block_validator = null,
        private ?HubContentSanitizer $content_sanitizer = null,
        private ?EventHubSlugValidator $slug_validator = null,
        private ?EventHubCacheService $cache_service = null,
    ) {
        $this->event_service ??= new EventService(new UserService());
        $this->block_validator ??= new HubBlockValidator();
        $this->content_sanitizer ??= new HubContentSanitizer();
        $this->slug_validator ??= new EventHubSlugValidator();
        $this->cache_service ??= new EventHubCacheService();
    }

    public function getAdminHub(int $event_id, int $user_id): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $hub = EventHub::where('event_id', $event_id)->first();

        if (!$hub) {
            return $this->defaultAdminScaffold($event);
        }

        return $this->formatAdminHub($hub);
    }

    public function updateHub(int $event_id, int $user_id, array $data): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $existing = EventHub::where('event_id', $event_id)->first();
        $old_slug = $existing?->slug;

        $slug = $this->slug_validator->normalize($data['slug'] ?? null);
        $is_published = (bool) $data['is_published'];
        $hub_config = $data['hub_config'];

        if ($is_published && ($slug === null || $slug === '')) {
            throw new Exception(MessagesEnum::EVENT_HUB_SLUG_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($slug !== null) {
            $this->slug_validator->validate($slug, $event_id);
        }

        $this->block_validator->validate($hub_config);
        $hub_config = $this->content_sanitizer->sanitizeHubConfig($hub_config);

        $hub = $existing ?? new EventHub(['event_id' => $event_id]);
        $hub->slug = $slug;
        $hub->is_published = $is_published;
        $hub->hub_config = $hub_config;
        $hub->save();

        $this->cache_service->forget($old_slug);
        $this->cache_service->forget($hub->slug);

        return [
            'updated_at' => $hub->updated_at?->toIso8601String(),
        ];
    }

    public function getPublicHub(string $slug): array
    {
        $slug = strtolower(trim($slug));

        return $this->cache_service->remember($slug, function () use ($slug) {
            $hub = EventHub::where('slug', $slug)->first();

            if (!$hub || !$hub->is_published) {
                throw new Exception(MessagesEnum::EVENT_HUB_NOT_FOUND, Response::HTTP_NOT_FOUND);
            }

            $event = Event::with('icebreakerConfig')
                ->select('id', 'name', 'path')
                ->find($hub->event_id);

            if (!$event) {
                throw new Exception(MessagesEnum::EVENT_HUB_NOT_FOUND, Response::HTTP_NOT_FOUND);
            }

            return $this->formatPublicHub($hub, $event);
        });
    }

    private function defaultAdminScaffold(Event $event): array
    {
        $cover_image_url = $event->image
            ? config('app.storage_url') . '/' . $event->image
            : null;

        $blocks = [
            [
                'id'      => 'blk_hero_' . Str::lower(Str::random(4)),
                'type'    => HubBlockTypeEnum::HERO_COUNTDOWN,
                'enabled' => true,
                'order'   => 1,
                'data'    => array_filter([
                    'title'           => $event->name ?: 'Our Event',
                    'event_date'      => $event->starts_at
                        ? Carbon::parse($event->starts_at)->toIso8601String()
                        : now()->addMonths(3)->toIso8601String(),
                    'cover_image_url' => $cover_image_url,
                ]),
            ],
            [
                'id'      => 'blk_cta_' . Str::lower(Str::random(4)),
                'type'    => HubBlockTypeEnum::SNAPSHARE_CTA,
                'enabled' => true,
                'order'   => 2,
                'data'    => [
                    'button_text' => config('event_hub.default_snapshare_cta_text'),
                ],
            ],
        ];

        return [
            'event_id'     => $event->id,
            'slug'         => null,
            'is_published' => false,
            'hub_config'   => [
                'theme'  => config('event_hub.default_theme'),
                'blocks' => $blocks,
            ],
            'updated_at'   => null,
        ];
    }

    private function formatAdminHub(EventHub $hub): array
    {
        return [
            'event_id'     => $hub->event_id,
            'slug'         => $hub->slug,
            'is_published' => $hub->is_published,
            'hub_config'   => $hub->hub_config ?? ['theme' => [], 'blocks' => []],
            'updated_at'   => $hub->updated_at?->toIso8601String(),
        ];
    }

    private function formatPublicHub(EventHub $hub, Event $event): array
    {
        $config = $hub->hub_config ?? ['theme' => [], 'blocks' => []];
        $blocks = collect($config['blocks'] ?? [])
            ->filter(fn ($block) => is_array($block) && !empty($block['enabled']))
            ->sortBy('order')
            ->values()
            ->map(function (array $block) {
                unset($block['enabled']);

                return $block;
            })
            ->all();

        return [
            'event_id'     => $event->id,
            'event_name'   => $event->name,
            'theme'        => $config['theme'] ?? [],
            'blocks'       => $blocks,
            'integrations' => [
                'gallery_path'       => $event->path,
                'gallery_url'        => '/' . $event->path,
                'icebreaker_enabled' => $this->isIcebreakerActive($event),
            ],
        ];
    }

    private function isIcebreakerActive(Event $event): bool
    {
        $config = $event->icebreakerConfig;

        if (!$config) {
            return false;
        }

        return (int) $config->status === IcebreakerStatusEnum::ACTIVE;
    }
}
