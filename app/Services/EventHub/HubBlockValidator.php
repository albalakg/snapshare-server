<?php

namespace App\Services\EventHub;

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
    public function __construct(
        private ?ProfanityFilterService $profanity_service = null,
    ) {
        $this->profanity_service ??= new ProfanityFilterService();
    }

    public function validate(array $hub_config): void
    {
        $blocks = $hub_config['blocks'] ?? [];

        if (!is_array($blocks)) {
            throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->validateTheme($hub_config['theme'] ?? []);
        $this->validateBlockStructure($blocks);

        foreach ($blocks as $block) {
            $this->validateBlockData($block);
            $this->moderateBlockText($block);
        }
    }

    private function validateTheme(array $theme): void
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
            throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function validateBlockStructure(array $blocks): void
    {
        $ids = [];
        $orders = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            foreach (['id', 'type', 'enabled', 'order', 'data'] as $field) {
                if (!array_key_exists($field, $block)) {
                    throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            if (!in_array($block['type'], HubBlockTypeEnum::validKeys(), true)) {
                throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (in_array($block['id'], $ids, true)) {
                throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $ids[] = $block['id'];
            $orders[] = (int) $block['order'];
        }

        if ($blocks !== []) {
            sort($orders);
            $expected = range(1, count($blocks));

            if ($orders !== $expected) {
                throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
    }

    private function validateBlockData(array $block): void
    {
        $type = $block['type'];
        $data = $block['data'] ?? [];

        if (!is_array($data)) {
            throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
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
                'alignment' => ['nullable', 'string', Rule::in(['LEFT', 'CENTER'])],
            ],
            HubBlockTypeEnum::MEDIA_STRIP => [
                'images'          => 'required|array|min:1',
                'images.*.url'    => 'required|url|max:2048',
                'images.*.caption'=> 'nullable|string|max:200',
            ],
            default => null,
        };

        if ($rules === null) {
            throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new Exception(MessagesEnum::EVENT_HUB_INVALID_BLOCKS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
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
}
