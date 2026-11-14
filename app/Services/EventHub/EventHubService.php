<?php

namespace App\Services\EventHub;

use App\Models\Event;
use App\Models\EventAsset;
use App\Models\EventHub;
use App\Services\Enums\EventAssetTypeEnum;
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

        $this->block_validator->validate($hub_config, $event_id);
        $this->forceMandatoryBlocksEnabled($hub_config);
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

    public function publishHub(int $event_id, int $user_id, array $data): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $hub = EventHub::where('event_id', $event_id)->first();
        if (!$hub) {
            throw new Exception(MessagesEnum::EVENT_HUB_NOT_SAVED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $is_published = (bool) $data['is_published'];
        $old_slug = $hub->slug;

        if ($is_published) {
            $slug = $this->slug_validator->normalize($data['slug'] ?? $hub->slug);
            if ($slug === null || $slug === '') {
                throw new Exception(MessagesEnum::EVENT_HUB_SLUG_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->slug_validator->validate($slug, $event_id);
            $hub->slug = $slug;
        }

        $hub->is_published = $is_published;
        $hub->save();

        $this->cache_service->forget($old_slug);
        $this->cache_service->forget($hub->slug);

        return [
            'is_published' => (bool) $hub->is_published,
            'slug'         => $hub->slug,
            'updated_at'   => $hub->updated_at?->toIso8601String(),
        ];
    }

    public function checkSlug(int $event_id, int $user_id, ?string $slug): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        return $this->slug_validator->availability($slug, $event_id);
    }

    public function listHubAssets(int $event_id, int $user_id, int $page = 1, int $per_page = 24): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $page = max(1, $page);
        $per_page = max(1, min(48, $per_page));

        $paginator = EventAsset::query()
            ->where('event_id', $event_id)
            ->visibleInGallery()
            ->where('asset_type', EventAssetTypeEnum::IMAGE_ID)
            ->orderByDesc('created_at')
            ->paginate($per_page, ['*'], 'page', $page);

        $storage = rtrim((string) config('app.storage_url'), '/');

        $data = collect($paginator->items())->map(function (EventAsset $asset) use ($storage) {
            $url = $storage . '/' . ltrim((string) $asset->path, '/');

            return [
                'id'         => $asset->id,
                'url'        => $url,
                'thumb_url'  => $url,
                'width'      => null,
                'height'     => null,
                'created_at' => $asset->created_at?->toIso8601String(),
            ];
        })->all();

        return [
            'data'         => $data,
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
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
                    'subtitle'    => config('event_hub.default_snapshare_cta_subtitle', 'בלי אפליקציה'),
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
        $template_id = $config['template_id'] ?? null;
        $is_template = is_string($template_id) && $template_id !== '';

        $theme = $config['theme'] ?? [];
        $blocks = collect($config['blocks'] ?? [])
            ->filter(fn ($block) => is_array($block) && !empty($block['enabled']))
            ->sortBy('order')
            ->values()
            ->map(function (array $block) {
                unset($block['enabled']);

                return $block;
            })
            ->all();

        if ($is_template) {
            $asset_map = $this->loadVisibleImageAssetMap($event->id, $theme, $blocks);
            $theme = $this->resolveImageRefsInNode($theme, $asset_map);
            $blocks = $this->resolveImageRefsInNode($blocks, $asset_map);

            return [
                'event_id'     => $event->id,
                'event_name'   => $event->name,
                'template_id'  => $template_id,
                'theme'        => $theme,
                'blocks'       => $blocks,
                'integrations' => [
                    'gallery_path'       => $event->path,
                    'gallery_url'        => '/' . $event->path,
                    'icebreaker_enabled' => $this->isIcebreakerActive($event),
                ],
            ];
        }

        return [
            'event_id'     => $event->id,
            'event_name'   => $event->name,
            'theme'        => $theme,
            'blocks'       => $blocks,
            'integrations' => [
                'gallery_path'       => $event->path,
                'gallery_url'        => '/' . $event->path,
                'icebreaker_enabled' => $this->isIcebreakerActive($event),
            ],
        ];
    }

    /**
     * Force HERO_COUNTDOWN and FOOTER enabled when a template is set.
     */
    private function forceMandatoryBlocksEnabled(array &$hub_config): void
    {
        $template_id = $hub_config['template_id'] ?? null;
        if (!is_string($template_id) || $template_id === '') {
            return;
        }

        if (!isset($hub_config['blocks']) || !is_array($hub_config['blocks'])) {
            return;
        }

        foreach ($hub_config['blocks'] as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            if ($type === HubBlockTypeEnum::HERO_COUNTDOWN || $type === HubBlockTypeEnum::FOOTER) {
                $hub_config['blocks'][$index]['enabled'] = true;
            }
        }
    }

    /**
     * @return array<int, EventAsset>
     */
    private function loadVisibleImageAssetMap(int $event_id, array $theme, array $blocks): array
    {
        $ids = [];
        $this->collectAssetIdsFromNode($theme['cover'] ?? null, $ids);

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $this->collectAssetIdsFromNode($block['data'] ?? [], $ids);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return EventAsset::query()
            ->where('event_id', $event_id)
            ->whereIn('id', $ids)
            ->visibleInGallery()
            ->where('asset_type', EventAssetTypeEnum::IMAGE_ID)
            ->get()
            ->keyBy(fn (EventAsset $asset) => (int) $asset->id)
            ->all();
    }

    private function collectAssetIdsFromNode(mixed $node, array &$ids): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($this->isImageRefArray($node)) {
            if (array_key_exists('asset_id', $node)) {
                $ids[] = (int) $node['asset_id'];
            }

            return;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectAssetIdsFromNode($value, $ids);
            }
        }
    }

    /**
     * @param array<int, EventAsset> $asset_map
     */
    private function resolveImageRefsInNode(mixed $node, array $asset_map): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        if ($this->isImageRefArray($node)) {
            return $this->resolveImageRef($node, $asset_map);
        }

        if (array_is_list($node)) {
            $resolved_list = [];

            foreach ($node as $item) {
                if (!is_array($item)) {
                    $resolved_list[] = $item;
                    continue;
                }

                if ($this->isImageRefArray($item)) {
                    $resolved = $this->resolveImageRef($item, $asset_map);
                    if ($resolved !== null) {
                        $resolved_list[] = $resolved;
                    }
                    continue;
                }

                $had_image_ref = array_key_exists('image', $item) && is_array($item['image']) && $this->isImageRefArray($item['image']);
                $resolved_item = $this->resolveImageRefsInNode($item, $asset_map);

                if ($had_image_ref && is_array($resolved_item) && ($resolved_item['image'] ?? null) === null) {
                    continue;
                }

                $resolved_list[] = $resolved_item;
            }

            return $resolved_list;
        }

        $resolved = [];
        foreach ($node as $key => $value) {
            $resolved[$key] = $this->resolveImageRefsInNode($value, $asset_map);
        }

        return $resolved;
    }

    /**
     * @param array<int, EventAsset> $asset_map
     */
    private function resolveImageRef(array $ref, array $asset_map): ?array
    {
        if (array_key_exists('stock', $ref)) {
            return [
                'type' => 'stock',
                'key'  => $ref['stock'],
            ];
        }

        $asset_id = (int) ($ref['asset_id'] ?? 0);
        $asset = $asset_map[$asset_id] ?? null;

        if (!$asset) {
            return null;
        }

        $url = rtrim((string) config('app.storage_url'), '/') . '/' . ltrim((string) $asset->path, '/');

        return [
            'type'      => 'asset',
            'asset_id'  => $asset_id,
            'url'       => $url,
            'thumb_url' => $url,
        ];
    }

    private function isImageRefArray(array $node): bool
    {
        $has_asset = array_key_exists('asset_id', $node);
        $has_stock = array_key_exists('stock', $node);

        return count($node) === 1 && ($has_asset xor $has_stock);
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
