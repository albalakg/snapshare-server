<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAsset;
use App\Models\User;
use App\Services\Enums\EventAssetTypeEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class HubAssetSelectionTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User();
        $this->user->role_id = RoleEnum::USER_ID;
        $this->user->first_name = 'Hub';
        $this->user->last_name = 'Assets';
        $this->user->email = 'hub-assets-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'hub-assets-' . Str::lower(Str::random(10));
        $this->event->name = 'Hub Assets Event';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    public function test_assets_endpoint_returns_only_own_visible_images_paginated(): void
    {
        $older = $this->createImageAsset($this->event->id, [
            'path' => 'events/hub/older-' . Str::uuid() . '.jpg',
        ]);
        $newer = $this->createImageAsset($this->event->id, [
            'path' => 'events/hub/newer-' . Str::uuid() . '.jpg',
        ]);
        $older->created_at = now()->subMinute();
        $older->save();
        $newer->created_at = now();
        $newer->save();

        $this->createImageAsset($this->event->id, [
            'asset_type' => EventAssetTypeEnum::VIDEO_ID,
            'path' => 'events/hub/video-' . Str::uuid() . '.mp4',
        ]);
        $this->createImageAsset($this->event->id, [
            'is_displayed' => false,
            'path' => 'events/hub/hidden-' . Str::uuid() . '.jpg',
        ]);

        $other = new Event();
        $other->user_id = $this->user->id;
        $other->path = 'hub-assets-other-' . Str::lower(Str::random(10));
        $other->name = 'Other Assets Event';
        $other->status = StatusEnum::ACTIVE;
        $other->save();
        $this->createImageAsset($other->id);

        $response = $this->actingAs($this->user, 'api')->getJson(
            "/api/events/{$this->event->id}/hub/assets"
        );
        $response->assertOk();

        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertSame([(int) $newer->id, (int) $older->id], array_map('intval', $ids));
        $this->assertSame(2, (int) $response->json('data.total'));
    }

    public function test_per_page_capped_at_48(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createImageAsset($this->event->id);
        }

        $response = $this->actingAs($this->user, 'api')->getJson(
            "/api/events/{$this->event->id}/hub/assets?per_page=100"
        );
        $response->assertOk();
        $this->assertSame(48, (int) $response->json('data.per_page'));
    }

    public function test_assets_guarded_by_ownership(): void
    {
        $intruder = new User();
        $intruder->role_id = RoleEnum::USER_ID;
        $intruder->first_name = 'Intruder';
        $intruder->last_name = 'Assets';
        $intruder->email = 'hub-assets-intruder-' . uniqid() . '@example.com';
        $intruder->password = 'Password1';
        $intruder->status = StatusEnum::ACTIVE;
        $intruder->save();

        $response = $this->actingAs($intruder, 'api')->getJson(
            "/api/events/{$this->event->id}/hub/assets"
        );
        $response->assertStatus(400);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_NOT_AUTHORIZED,
            (string) $response->json('message')
        );
    }

    public function test_public_payload_resolves_asset_and_stock_refs(): void
    {
        $asset = $this->createImageAsset($this->event->id);
        $slug = 'asset-resolve-' . Str::lower(Str::random(8));

        $config = $this->wedding1Config([
            'theme' => ['cover' => ['stock' => 'wedding-1/hero']],
        ]);
        $config['blocks'][0]['data']['cover'] = ['asset_id' => $asset->id];
        $config['blocks'] = [
            $config['blocks'][0],
            [
                'id' => 'gallery1',
                'type' => 'GALLERY',
                'enabled' => true,
                'order' => 2,
                'data' => [
                    'heading' => 'גלריה',
                    'images' => [
                        ['image' => ['asset_id' => $asset->id], 'caption' => 'אחת'],
                    ],
                ],
            ],
            $config['blocks'][2],
            [
                'id' => 'footer1',
                'type' => 'FOOTER',
                'enabled' => true,
                'order' => 4,
                'data' => ['note' => 'SnapShare'],
            ],
        ];
        $config['blocks'][2]['order'] = 3;

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $config,
            ]
        )->assertOk();

        $public = $this->getJson("/api/public/hubs/{$slug}");
        $public->assertOk();

        $theme_cover = $public->json('data.theme.cover');
        $this->assertSame('stock', $theme_cover['type']);
        $this->assertSame('wedding-1/hero', $theme_cover['key']);

        $hero = collect($public->json('data.blocks'))->firstWhere('type', 'HERO_COUNTDOWN');
        $this->assertSame('asset', $hero['data']['cover']['type']);
        $this->assertSame((int) $asset->id, (int) $hero['data']['cover']['asset_id']);
        $this->assertNotEmpty($hero['data']['cover']['url']);
        $this->assertNotEmpty($hero['data']['cover']['thumb_url']);

        $gallery = collect($public->json('data.blocks'))->firstWhere('type', 'GALLERY');
        $this->assertSame('asset', $gallery['data']['images'][0]['image']['type']);
        $this->assertSame((int) $asset->id, (int) $gallery['data']['images'][0]['image']['asset_id']);
    }

    public function test_blocking_asset_drops_from_public_payload_immediately(): void
    {
        $asset = $this->createImageAsset($this->event->id);
        $slug = 'asset-block-' . Str::lower(Str::random(8));

        $config = $this->wedding1Config();
        $config['blocks'][0]['data']['cover'] = ['asset_id' => $asset->id];

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $config,
            ]
        )->assertOk();

        $before = $this->getJson("/api/public/hubs/{$slug}");
        $before->assertOk();
        $hero_before = collect($before->json('data.blocks'))->firstWhere('type', 'HERO_COUNTDOWN');
        $this->assertSame((int) $asset->id, (int) $hero_before['data']['cover']['asset_id']);

        $this->actingAs($this->user, 'api')->postJson(
            "/api/events/{$this->event->id}/assets/block",
            ['assets' => [$asset->id]]
        )->assertOk();

        $after_block = $this->getJson("/api/public/hubs/{$slug}");
        $after_block->assertOk();
        $hero_blocked = collect($after_block->json('data.blocks'))->firstWhere('type', 'HERO_COUNTDOWN');
        $this->assertNull($hero_blocked['data']['cover'] ?? null);

        $this->actingAs($this->user, 'api')->postJson(
            "/api/events/{$this->event->id}/assets/unblock",
            ['assets' => [$asset->id]]
        )->assertOk();

        $after_unblock = $this->getJson("/api/public/hubs/{$slug}");
        $after_unblock->assertOk();
        $hero_unblocked = collect($after_unblock->json('data.blocks'))->firstWhere('type', 'HERO_COUNTDOWN');
        $this->assertSame((int) $asset->id, (int) $hero_unblocked['data']['cover']['asset_id']);
    }

    public function test_scaffold_get_hub_has_hebrew_cta_when_no_hub_saved(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson(
            "/api/events/{$this->event->id}/hub"
        );
        $response->assertOk();
        $this->assertNull($response->json('data.updated_at'));

        $cta = collect($response->json('data.hub_config.blocks'))
            ->firstWhere('type', 'SNAPSHARE_CTA');
        $this->assertNotNull($cta);
        $this->assertSame(
            config('event_hub.default_snapshare_cta_text'),
            $cta['data']['button_text']
        );
        $this->assertSame(
            config('event_hub.default_snapshare_cta_subtitle'),
            $cta['data']['subtitle']
        );
        $this->assertSame('העלו תמונות', $cta['data']['button_text']);
    }

    private function wedding1Config(array $overrides = []): array
    {
        $config = [
            'template_id' => 'wedding-1',
            'theme' => [
                'palette_id' => 'default',
                'background_type' => 'LIGHT',
                'font_family' => 'Frank Ruhl Libre',
                'scale' => 'M',
                'cover' => ['stock' => 'wedding-1/hero'],
            ],
            'blocks' => [
                [
                    'id' => 'hero1',
                    'type' => 'HERO_COUNTDOWN',
                    'enabled' => true,
                    'order' => 1,
                    'data' => [
                        'title' => 'Gal & Amit',
                        'event_date' => '2026-12-01',
                        'kicker' => 'חתונה',
                        'cover' => ['stock' => 'art/blush'],
                    ],
                ],
                [
                    'id' => 'story1',
                    'type' => 'STORY',
                    'enabled' => true,
                    'order' => 2,
                    'data' => [
                        'heading' => 'הסיפור שלנו',
                        'chapters' => [
                            ['title' => 'פרק', 'body' => 'טקסט'],
                        ],
                    ],
                ],
                [
                    'id' => 'cta1',
                    'type' => 'SNAPSHARE_CTA',
                    'enabled' => true,
                    'order' => 3,
                    'data' => [
                        'button_text' => 'העלו תמונות',
                        'subtitle' => 'בלי אפליקציה',
                    ],
                ],
                [
                    'id' => 'footer1',
                    'type' => 'FOOTER',
                    'enabled' => true,
                    'order' => 4,
                    'data' => ['note' => 'SnapShare'],
                ],
            ],
        ];

        return array_replace_recursive($config, $overrides);
    }

    private function createImageAsset(int $event_id, array $opts = []): EventAsset
    {
        $asset = new EventAsset();
        $asset->event_id = $event_id;
        $asset->asset_type = $opts['asset_type'] ?? EventAssetTypeEnum::IMAGE_ID;
        $asset->path = $opts['path'] ?? ('events/hub-test/' . Str::uuid() . '.jpg');
        $asset->status = $opts['status'] ?? StatusEnum::ACTIVE;
        $asset->is_displayed = $opts['is_displayed'] ?? true;
        $asset->user_agent = 'phpunit';
        $asset->ip = '127.0.0.1';
        $asset->save();

        return $asset;
    }
}
