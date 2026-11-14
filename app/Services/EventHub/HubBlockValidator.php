<?php

namespace App\Services\EventHub;

use App\Models\EventAsset;
use App\Services\Enums\EventAssetTypeEnum;
use App\Services\Enums\HubBackgroundTypeEnum;
use App\Services\Enums\HubBlockTypeEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Moderation\ProfanityFilterService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HubBlockValidator
{
    private const HEX_COLOR = '/^#[0-9A-Fa-f]{6}$/';

    public function __construct(
        private ?ProfanityFilterService $profanity_service = null,
    ) {
        $this->profanity_service ??= new ProfanityFilterService();
    }

    public function validate(array $hub_config, ?int $event_id = null): void
    {
        $blocks = $hub_config['blocks'] ?? [];

        if (!is_array($blocks)) {
            $this->invalidBlocks();
        }

        $template_id = $hub_config['template_id'] ?? null;
        $is_template = is_string($template_id) && $template_id !== '';

        if ($is_template) {
            $template = EventLandingRegistry::template($template_id);
            if ($template === null) {
                $this->invalidBlocks();
            }

            $this->validateTemplateTheme($hub_config['theme'] ?? [], $template);
            $this->validateBlockStructure($blocks, true, $template);
            $this->validateTemplateMandatoryBlocks($blocks);

            foreach ($blocks as $block) {
                $this->validateTemplateBlockData($block);
                $this->moderateBlockText($block);
            }

            $this->validateAssetOwnership($hub_config, $event_id);

            return;
        }

        $this->validateLegacyTheme($hub_config['theme'] ?? []);
        $this->validateBlockStructure($blocks, false, null);

        foreach ($blocks as $block) {
            if (!EventLandingRegistry::isLegacyBlockType($block['type'])) {
                $this->invalidBlocks();
            }

            $this->validateLegacyBlockData($block);
            $this->moderateBlockText($block);
        }
    }

    private function validateLegacyTheme(array $theme): void
    {
        if ($theme === []) {
            return;
        }

        $validator = Validator::make($theme, [
            'primary_color'    => 'nullable|string|max:32',
            'secondary_color'  => 'nullable|string|max:32',
            'background_type'  => ['nullable', 'string', Rule::in(HubBackgroundTypeEnum::validKeys())],
            'font_family'      => 'nullable|string|max:64',
            'accent_image_url' => 'nullable|url|max:2048',
        ]);

        if ($validator->fails()) {
            $this->invalidBlocks();
        }
    }

    private function validateTemplateTheme(array $theme, array $template): void
    {
        if (!is_array($theme)) {
            $this->invalidBlocks();
        }

        if (array_key_exists('accent_image_url', $theme) && $theme['accent_image_url'] !== null) {
            $this->invalidBlocks();
        }

        $palette_ids = array_keys($template['palettes'] ?? []);

        $validator = Validator::make($theme, [
            'palette_id'       => ['required', 'string', Rule::in($palette_ids)],
            'primary_color'    => 'nullable|string|max:32',
            'secondary_color'  => 'nullable|string|max:32',
            'background_type'  => ['nullable', 'string', Rule::in(HubBackgroundTypeEnum::validKeys())],
            'font_family'      => ['nullable', 'string', Rule::in(EventLandingRegistry::allowedFonts())],
            'scale'            => ['nullable', 'string', Rule::in(config('event_landing.scales', ['S', 'M', 'L']))],
            'colors'           => 'nullable|array',
            'colors.primary'   => ['nullable', 'string', 'regex:' . self::HEX_COLOR],
            'colors.secondary' => ['nullable', 'string', 'regex:' . self::HEX_COLOR],
            'colors.accent'    => ['nullable', 'string', 'regex:' . self::HEX_COLOR],
            'colors.background'=> ['nullable', 'string', 'regex:' . self::HEX_COLOR],
            'colors.text'      => ['nullable', 'string', 'regex:' . self::HEX_COLOR],
            'cover'            => 'nullable|array',
        ]);

        if ($validator->fails()) {
            $this->invalidBlocks();
        }

        if (array_key_exists('cover', $theme) && $theme['cover'] !== null) {
            $this->assertImageRef($theme['cover']);
        }
    }

    private function validateBlockStructure(array $blocks, bool $template_mode, ?array $template): void
    {
        $ids = [];
        $orders = [];
        $allowed_sections = $template['allowed_sections'] ?? [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                $this->invalidBlocks();
            }

            foreach (['id', 'type', 'enabled', 'order', 'data'] as $field) {
                if (!array_key_exists($field, $block)) {
                    $this->invalidBlocks();
                }
            }

            $type = $block['type'];

            if (!is_string($type)) {
                $this->invalidBlocks();
            }

            if ($template_mode) {
                if (!in_array($type, $allowed_sections, true)) {
                    $this->invalidBlocks();
                }

                if (!$this->isRecognizedBlockType($type)) {
                    $this->invalidBlocks();
                }
            } elseif (!in_array($type, HubBlockTypeEnum::validKeys(), true)) {
                $this->invalidBlocks();
            }

            if (in_array($block['id'], $ids, true)) {
                $this->invalidBlocks();
            }

            $ids[] = $block['id'];
            $orders[] = (int) $block['order'];
        }

        if ($blocks !== []) {
            sort($orders);
            $expected = range(1, count($blocks));

            if ($orders !== $expected) {
                $this->invalidBlocks();
            }
        }
    }

    private function isRecognizedBlockType(string $type): bool
    {
        if (in_array($type, HubBlockTypeEnum::validKeys(), true)) {
            return true;
        }

        foreach (EventLandingRegistry::templateIds() as $template_id) {
            $template = EventLandingRegistry::template($template_id);
            if ($template && in_array($type, $template['allowed_sections'] ?? [], true)) {
                return true;
            }
        }

        return EventLandingRegistry::isLegacyBlockType($type);
    }

    private function validateTemplateMandatoryBlocks(array $blocks): void
    {
        $count = count($blocks);
        $hero_count = 0;
        $footer_count = 0;

        foreach ($blocks as $block) {
            $order = (int) $block['order'];

            if ($block['type'] === HubBlockTypeEnum::HERO_COUNTDOWN) {
                $hero_count++;
                if ($order !== 1) {
                    $this->invalidBlocks();
                }
            }

            if ($block['type'] === 'FOOTER') {
                $footer_count++;
                if ($order !== $count) {
                    $this->invalidBlocks();
                }
            }
        }

        if ($hero_count !== 1 || $footer_count !== 1) {
            $this->invalidBlocks();
        }
    }

    private function validateLegacyBlockData(array $block): void
    {
        $type = $block['type'];
        $data = $block['data'] ?? [];

        if (!is_array($data)) {
            $this->invalidBlocks();
        }

        $rules = match ($type) {
            HubBlockTypeEnum::HERO_COUNTDOWN => [
                'title'           => 'required|string|max:200',
                'event_date'      => 'required|date',
                'cover_image_url' => 'nullable|url|max:2048',
            ],
            HubBlockTypeEnum::SNAPSHARE_CTA => [
                'button_text' => 'required|string|max:120',
                'subtitle'    => 'nullable|string|max:200',
            ],
            HubBlockTypeEnum::EVENT_TIMELINE => [
                'items'               => 'required|array|min:1',
                'items.*.time'        => 'required|string|max:50',
                'items.*.label'       => 'required|string|max:120',
                'items.*.description' => 'nullable|string|max:500',
            ],
            HubBlockTypeEnum::SMART_NAVIGATION => [
                'location_name'   => 'required|string|max:200',
                'waze_url'        => 'nullable|url|max:2048',
                'google_maps_url' => 'nullable|url|max:2048',
            ],
            HubBlockTypeEnum::WISHING_WELL => [
                'title'           => 'required|string|max:120',
                'message'         => 'nullable|string|max:1000',
                'payment_link'    => 'nullable|url|max:2048',
                'account_details' => 'nullable|string|max:500',
            ],
            HubBlockTypeEnum::LINK_LIST => [
                'links'         => 'required|array|min:1',
                'links.*.label' => 'required|string|max:120',
                'links.*.url'   => 'required|url|max:2048',
                'links.*.icon'  => 'nullable|string|max:64',
            ],
            HubBlockTypeEnum::RICH_TEXT => [
                'heading'   => 'required|string|max:200',
                'body'      => 'required|string|max:5000',
                'alignment' => ['nullable', 'string', Rule::in(['LEFT', 'CENTER', 'RIGHT'])],
            ],
            HubBlockTypeEnum::MEDIA_STRIP => [
                'images'           => 'required|array|min:1',
                'images.*.url'     => 'required|url|max:2048',
                'images.*.caption' => 'nullable|string|max:200',
            ],
            default => null,
        };

        if ($rules === null) {
            $this->invalidBlocks();
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $this->invalidBlocks();
        }
    }

    private function validateTemplateBlockData(array $block): void
    {
        $type = $block['type'];
        $data = $block['data'] ?? [];

        if (!is_array($data)) {
            $this->invalidBlocks();
        }

        $https = $this->httpsUrlRules(false);

        $rules = match ($type) {
            HubBlockTypeEnum::HERO_COUNTDOWN, 'HERO_COUNTDOWN' => [
                'title'           => 'required|string|max:200',
                'event_date'      => 'required|date',
                'kicker'          => 'nullable|string|max:200',
                'venue'           => 'nullable|string|max:200',
                'city'            => 'nullable|string|max:200',
                'welcome_heading' => 'nullable|string|max:200',
                'welcome'         => 'nullable|string|max:5000',
                'cover'           => 'nullable|array',
                'cover_image_url' => 'prohibited',
            ],
            'STORY' => [
                'kicker'        => 'nullable|string|max:200',
                'heading'       => 'nullable|string|max:200',
                'chapters'      => 'nullable|array|max:24',
                'chapters.*.title' => 'nullable|string|max:200',
                'chapters.*.body'  => 'nullable|string|max:5000',
                'image'         => 'nullable|array',
                'image_caption' => 'nullable|string|max:200',
            ],
            'ABOUT' => [
                'kicker'  => 'nullable|string|max:200',
                'heading' => 'nullable|string|max:200',
                'body'    => 'nullable|string|max:5000',
                'layout'  => ['nullable', 'string', Rule::in(config('event_landing.about_layouts', ['center', 'split', 'wide', 'right']))],
                'image'   => 'nullable|array',
            ],
            'PEOPLE' => [
                'kicker'            => 'nullable|string|max:200',
                'heading'           => 'nullable|string|max:200',
                'note'              => 'nullable|string|max:1000',
                'items'             => 'nullable|array|max:24',
                'items.*.initials'  => 'nullable|string|max:16',
                'items.*.name'      => 'nullable|string|max:200',
                'items.*.role'      => 'nullable|string|max:200',
                'photos'            => 'nullable|array|max:24',
                'photos.*.image'    => 'nullable|array',
                'photos.*.caption'  => 'nullable|string|max:200',
            ],
            HubBlockTypeEnum::EVENT_TIMELINE, 'EVENT_TIMELINE' => [
                'kicker'              => 'nullable|string|max:200',
                'heading'             => 'nullable|string|max:200',
                'items'               => 'required|array|min:1|max:24',
                'items.*.time'        => 'required|string|max:50',
                'items.*.label'       => 'required|string|max:120',
                'items.*.description' => 'nullable|string|max:500',
            ],
            'CARDS' => [
                'kicker'         => 'nullable|string|max:200',
                'heading'        => 'nullable|string|max:200',
                'items'          => 'nullable|array|max:24',
                'items.*.title'  => 'nullable|string|max:200',
                'items.*.body'   => 'nullable|string|max:5000',
            ],
            'GALLERY' => [
                'kicker'            => 'nullable|string|max:200',
                'heading'           => 'nullable|string|max:200',
                'style'             => ['nullable', 'string', Rule::in(config('event_landing.gallery_styles', ['masonry', 'carousel', 'filmstrip', 'grid']))],
                'note'              => 'nullable|string|max:1000',
                'images'            => 'nullable|array|max:30',
                'images.*.image'    => 'nullable|array',
                'images.*.caption'  => 'nullable|string|max:200',
            ],
            'STEPS' => [
                'kicker'           => 'nullable|string|max:200',
                'heading'          => 'nullable|string|max:200',
                'steps'            => 'nullable|array|max:24',
                'steps.*.n'        => 'nullable|string|max:16',
                'steps.*.title'    => 'nullable|string|max:200',
                'steps.*.body'     => 'nullable|string|max:5000',
                'button_text'      => 'nullable|string|max:120',
                'album_text'       => 'nullable|string|max:200',
            ],
            'MAP' => [
                'kicker'          => 'nullable|string|max:200',
                'heading'         => 'nullable|string|max:200',
                'location_name'   => 'required|string|max:200',
                'city'            => 'nullable|string|max:200',
                'note'            => 'nullable|string|max:1000',
                'waze_url'        => $https,
                'google_maps_url' => $https,
            ],
            HubBlockTypeEnum::WISHING_WELL, 'WISHING_WELL' => [
                'title'           => 'required|string|max:120',
                'message'         => 'nullable|string|max:1000',
                'gift_label'      => 'nullable|string|max:200',
                'gift_note'       => 'nullable|string|max:500',
                'payment_link'    => $https,
                'account_details' => 'nullable|string|max:500',
            ],
            'RSVP' => [
                'kicker'  => 'nullable|string|max:200',
                'heading' => 'nullable|string|max:200',
                'body'    => 'nullable|string|max:5000',
            ],
            'FAQ' => [
                'kicker'       => 'nullable|string|max:200',
                'heading'      => 'nullable|string|max:200',
                'items'        => 'required|array|min:1|max:24',
                'items.*.q'    => 'required|string|max:500',
                'items.*.a'    => 'required|string|max:5000',
            ],
            'QUOTE' => [
                'text'   => 'required|string|max:1000',
                'credit' => 'nullable|string|max:200',
            ],
            'TRIVIA_CTA' => [
                'title' => 'nullable|string|max:200',
                'body'  => 'nullable|string|max:5000',
            ],
            'GIFT' => [
                'kicker'  => 'nullable|string|max:200',
                'heading' => 'nullable|string|max:200',
                'body'    => 'nullable|string|max:5000',
                'button'  => 'nullable|string|max:120',
                'url'     => $https,
            ],
            'MILESTONES' => [
                'kicker'        => 'nullable|string|max:200',
                'heading'       => 'nullable|string|max:200',
                'note'          => 'nullable|string|max:1000',
                'items'         => 'nullable|array|max:24',
                'items.*.tag'   => 'nullable|string|max:50',
                'items.*.title' => 'nullable|string|max:200',
                'items.*.body'  => 'nullable|string|max:5000',
            ],
            'PLAYLIST' => [
                'heading'        => 'nullable|string|max:200',
                'note'           => 'nullable|string|max:1000',
                'tracks'         => 'nullable|array|max:24',
                'tracks.*.song'  => 'nullable|string|max:200',
                'tracks.*.art'   => 'nullable|string|max:200',
                'tracks.*.dur'   => 'nullable|string|max:32',
            ],
            'SPEAKERS' => [
                'heading'           => 'nullable|string|max:200',
                'note'              => 'nullable|string|max:1000',
                'items'             => 'nullable|array|max:24',
                'items.*.initials'  => 'nullable|string|max:16',
                'items.*.name'      => 'nullable|string|max:200',
                'items.*.role'      => 'nullable|string|max:200',
            ],
            'SPONSORS' => [
                'kicker'            => 'nullable|string|max:200',
                'heading'           => 'nullable|string|max:200',
                'note'              => 'nullable|string|max:1000',
                'items'             => 'nullable|array|max:24',
                'items.*.initials'  => 'nullable|string|max:16',
                'items.*.name'      => 'nullable|string|max:200',
            ],
            'NETWORKING' => [
                'kicker'  => 'nullable|string|max:200',
                'heading' => 'nullable|string|max:200',
                'body'    => 'nullable|string|max:5000',
            ],
            'HASHTAG' => [
                'tag'  => 'required|string|max:100',
                'body' => 'nullable|string|max:5000',
            ],
            'LINEUP' => [
                'kicker'        => 'nullable|string|max:200',
                'heading'       => 'nullable|string|max:200',
                'note'          => 'nullable|string|max:1000',
                'items'         => 'nullable|array|max:24',
                'items.*.time'  => 'nullable|string|max:50',
                'items.*.title' => 'nullable|string|max:200',
                'items.*.body'  => 'nullable|string|max:5000',
            ],
            'BEEN_THERE' => [
                'heading' => 'nullable|string|max:200',
                'body'    => 'nullable|string|max:5000',
            ],
            'GROUNDS' => [
                'kicker'         => 'nullable|string|max:200',
                'heading'        => 'nullable|string|max:200',
                'note'           => 'nullable|string|max:1000',
                'zones'          => 'nullable|array|max:24',
                'zones.*.id'     => 'nullable|string|max:64',
                'zones.*.label'  => 'nullable|string|max:200',
            ],
            'ZONES' => [
                'kicker'         => 'nullable|string|max:200',
                'heading'        => 'nullable|string|max:200',
                'note'           => 'nullable|string|max:1000',
                'items'          => 'nullable|array|max:24',
                'items.*.title'  => 'nullable|string|max:200',
                'items.*.body'   => 'nullable|string|max:5000',
            ],
            'RULES' => [
                'kicker'  => 'nullable|string|max:200',
                'heading' => 'nullable|string|max:200',
                'items'   => 'nullable|array|max:24',
                'items.*' => 'nullable|string|max:1000',
            ],
            'FOOTER' => [
                'note' => 'nullable|string|max:1000',
            ],
            HubBlockTypeEnum::SNAPSHARE_CTA, 'SNAPSHARE_CTA' => [
                'button_text' => 'required|string|max:120',
                'subtitle'    => 'nullable|string|max:200',
            ],
            HubBlockTypeEnum::RICH_TEXT, 'RICH_TEXT' => [
                'heading'   => 'required|string|max:200',
                'body'      => 'required|string|max:5000',
                'alignment' => ['nullable', 'string', Rule::in(['LEFT', 'CENTER', 'RIGHT'])],
            ],
            HubBlockTypeEnum::LINK_LIST => [
                'links'         => 'required|array|min:1',
                'links.*.label' => 'required|string|max:120',
                'links.*.url'   => 'required|url|max:2048',
                'links.*.icon'  => 'nullable|string|max:64',
            ],
            HubBlockTypeEnum::SMART_NAVIGATION => [
                'location_name'   => 'required|string|max:200',
                'waze_url'        => 'nullable|url|max:2048',
                'google_maps_url' => 'nullable|url|max:2048',
            ],
            HubBlockTypeEnum::MEDIA_STRIP => [
                'images'           => 'required|array|min:1',
                'images.*.url'     => 'required|url|max:2048',
                'images.*.caption' => 'nullable|string|max:200',
            ],
            default => null,
        };

        if ($rules === null) {
            $this->invalidBlocks();
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $this->invalidBlocks();
        }

        $this->validateTemplateImageSlots($type, $data);
    }

    private function validateTemplateImageSlots(string $type, array $data): void
    {
        if (array_key_exists('cover', $data) && $data['cover'] !== null) {
            $this->assertImageRef($data['cover']);
        }

        if (array_key_exists('image', $data) && $data['image'] !== null) {
            $this->assertImageRef($data['image']);
        }

        if ($type === 'PEOPLE' && isset($data['photos']) && is_array($data['photos'])) {
            foreach ($data['photos'] as $photo) {
                if (!is_array($photo)) {
                    $this->invalidBlocks();
                }
                if (array_key_exists('image', $photo) && $photo['image'] !== null) {
                    $this->assertImageRef($photo['image']);
                }
            }
        }

        if ($type === 'GALLERY' && isset($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $image) {
                if (!is_array($image)) {
                    $this->invalidBlocks();
                }
                if (array_key_exists('image', $image) && $image['image'] !== null) {
                    $this->assertImageRef($image['image']);
                }
            }
        }
    }

    private function assertImageRef(mixed $ref): void
    {
        if (!is_array($ref)) {
            $this->invalidBlocks();
        }

        $has_asset = array_key_exists('asset_id', $ref);
        $has_stock = array_key_exists('stock', $ref);

        if ($has_asset === $has_stock) {
            $this->invalidBlocks();
        }

        if (count($ref) !== 1) {
            $this->invalidBlocks();
        }

        if ($has_asset) {
            if (!is_numeric($ref['asset_id']) || (int) $ref['asset_id'] < 1) {
                $this->invalidBlocks();
            }
        }

        if ($has_stock) {
            if (!is_string($ref['stock']) || $ref['stock'] === '' || !EventLandingRegistry::isValidStockKey($ref['stock'])) {
                $this->invalidBlocks();
            }
        }
    }

    private function validateAssetOwnership(array $hub_config, ?int $event_id): void
    {
        $asset_ids = $this->collectAssetIds($hub_config);

        if ($asset_ids === []) {
            return;
        }

        if ($event_id === null) {
            $this->invalidBlocks();
        }

        $found = EventAsset::query()
            ->where('event_id', $event_id)
            ->whereIn('id', $asset_ids)
            ->visibleInGallery()
            ->where('asset_type', EventAssetTypeEnum::IMAGE_ID)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_diff($asset_ids, $found);

        if ($missing !== []) {
            $this->invalidBlocks();
        }
    }

    /**
     * @return list<int>
     */
    private function collectAssetIds(array $hub_config): array
    {
        $ids = [];

        $this->collectAssetIdsFromNode($hub_config['theme']['cover'] ?? null, $ids);

        foreach ($hub_config['blocks'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $this->collectAssetIdsFromNode($block['data'] ?? [], $ids);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
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

    private function isImageRefArray(array $node): bool
    {
        $has_asset = array_key_exists('asset_id', $node);
        $has_stock = array_key_exists('stock', $node);

        return count($node) === 1 && ($has_asset xor $has_stock);
    }

    /**
     * @return list<string>
     */
    private function httpsUrlRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:2048',
            'url',
            'regex:/^https:\/\//i',
        ];
    }

    private function moderateBlockText(array $block): void
    {
        $strings = $this->collectStrings($block['data'] ?? []);

        foreach ($strings as $text) {
            if ($this->profanity_service->containsProfanity($text)) {
                throw new Exception(MessagesEnum::EVENT_HUB_CONTENT_REJECTED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
    }

    private function collectStrings(array $data): array
    {
        $strings = [];

        foreach ($data as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = $value;
            } elseif (is_array($value)) {
                $strings = array_merge($strings, $this->collectStrings($value));
            }
        }

        return $strings;
    }

    private function invalidBlocks(): never
    {
        throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
