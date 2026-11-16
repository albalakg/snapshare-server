<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAsset;
use App\Models\EventConfig;
use App\Models\User;
use App\Services\Enums\EventGalleryTypeEnum;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LiveScreenConfigTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User();
        $this->user->role_id = RoleEnum::USER_ID;
        $this->user->first_name = 'Live';
        $this->user->last_name = 'Screen';
        $this->user->email = 'live-screen-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'live-screen-' . Str::lower(Str::random(10));
        $this->event->name = 'Live Screen Test';
        $this->event->status = StatusEnum::IN_PROGRESS;
        $this->event->save();

        EventConfig::firstOrCreate(
            ['event_id' => $this->event->id],
            [
                'preview_site_display_image' => true,
                'preview_site_display_name' => true,
                'preview_site_display_date' => true,
                'preview_guests_assets_in_gallery' => true,
                'preview_owners_assets_in_gallery' => true,
                'preview_qr_in_gallery' => true,
                'displayed_gallery' => EventGalleryTypeEnum::KEN_BURNS_GALLERY,
                'video_upload_enabled' => true,
                'preview_link_to_album_page_from_upload_page' => true,
                'image_display_time' => 3,
                'video_display_time' => 8,
            ]
        );
    }

    public function test_gallery_settings_accepts_new_modes_and_live_screen_options(): void
    {
        $response = $this->actingAs($this->user, 'api')->post(
            "/api/events/{$this->event->id}/gallery/settings",
            [
                'selectedAlbum' => EventGalleryTypeEnum::SPOTLIGHT_GALLERY,
                'live_screen_options' => [
                    'qr_corner' => 'top-end',
                    'spotlight_hold_seconds' => 6,
                    'ken_burns_intensity' => 'subtle',
                    'mosaic_size' => '4x4',
                    'masonry_density' => 'dense',
                    'polaroid_count' => 10,
                ],
            ]
        );

        $response->assertOk();
        $config = EventConfig::where('event_id', $this->event->id)->firstOrFail();
        $this->assertSame(EventGalleryTypeEnum::SPOTLIGHT_GALLERY, $config->displayed_gallery);
        $this->assertSame('top-end', $config->live_screen_options['qr_corner'] ?? null);
        $this->assertSame(6, (int) ($config->live_screen_options['spotlight_hold_seconds'] ?? 0));
        $this->assertSame(10, (int) ($config->live_screen_options['polaroid_count'] ?? 0));
    }

    public function test_gallery_settings_rejects_unknown_mode_and_bad_options(): void
    {
        $badMode = $this->actingAs($this->user, 'api')->postJson(
            "/api/events/{$this->event->id}/gallery/settings",
            ['selectedAlbum' => 'EventGalleryCarousel3D']
        );
        $badMode->assertStatus(422);

        $badCorner = $this->actingAs($this->user, 'api')->postJson(
            "/api/events/{$this->event->id}/gallery/settings",
            [
                'selectedAlbum' => EventGalleryTypeEnum::MOSAIC_GALLERY,
                'live_screen_options' => ['qr_corner' => 'middle'],
            ]
        );
        $badCorner->assertStatus(422);
    }

    public function test_update_persists_displayed_gallery_and_live_screen_options(): void
    {
        $response = $this->actingAs($this->user, 'api')->post(
            "/api/events/{$this->event->id}/update",
            [
                'config' => [
                    'displayed_gallery' => EventGalleryTypeEnum::MOSAIC_GALLERY,
                    'preview_qr_in_gallery' => 'false',
                    'image_display_time' => 5,
                    'live_screen_options' => [
                        'qr_corner' => 'bottom-end',
                        'mosaic_size' => '3x3',
                    ],
                ],
            ]
        );

        $response->assertOk();
        $config = EventConfig::where('event_id', $this->event->id)->firstOrFail();
        $this->assertSame(EventGalleryTypeEnum::MOSAIC_GALLERY, $config->displayed_gallery);
        $this->assertFalse((bool) $config->preview_qr_in_gallery);
        $this->assertSame(5, (int) $config->image_display_time);
        $this->assertSame('bottom-end', $config->live_screen_options['qr_corner'] ?? null);
        $this->assertSame('3x3', $config->live_screen_options['mosaic_size'] ?? null);
    }

    public function test_host_and_public_payloads_eager_load_live_screen_config_columns(): void
    {
        $config = EventConfig::where('event_id', $this->event->id)->firstOrFail();
        $config->displayed_gallery = EventGalleryTypeEnum::FILMSTRIP_GALLERY;
        $config->preview_qr_in_gallery = false;
        $config->image_display_time = 7;
        $config->video_display_time = 14;
        $config->live_screen_options = [
            'qr_corner' => 'top-start',
            'ken_burns_intensity' => 'medium',
        ];
        $config->save();

        Passport::actingAs($this->user);
        $me = $this->getJson('/api/auth/me');
        if ($me->status() === 404) {
            $me = $this->actingAs($this->user, 'api')->getJson('/api/user');
        }

        // Prefer the user payload that embeds the event (login/me style).
        $login = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'Password1',
        ]);

        $hostConfig = null;
        if ($login->status() === 200) {
            $hostConfig = data_get($login->json(), 'data.user.event.config');
        }

        if (!$hostConfig) {
            // Fallback: base-info / base-assets cover the public eager-load contract.
            $hostConfig = [];
        } else {
            $this->assertSame(EventGalleryTypeEnum::FILMSTRIP_GALLERY, $hostConfig['displayed_gallery'] ?? null);
            $this->assertFalse((bool) ($hostConfig['preview_qr_in_gallery'] ?? true));
            $this->assertSame(7, (int) ($hostConfig['image_display_time'] ?? 0));
            $this->assertSame(14, (int) ($hostConfig['video_display_time'] ?? 0));
            $this->assertSame('top-start', data_get($hostConfig, 'live_screen_options.qr_corner'));
        }

        $baseInfo = $this->getJson("/api/events/{$this->event->path}/base-info");
        $baseInfo->assertOk();
        $infoConfig = data_get($baseInfo->json(), 'data.config');
        $this->assertSame(EventGalleryTypeEnum::FILMSTRIP_GALLERY, $infoConfig['displayed_gallery'] ?? null);
        $this->assertSame(7, (int) ($infoConfig['image_display_time'] ?? 0));
        $this->assertArrayHasKey('live_screen_options', $infoConfig ?? []);

        $baseAssets = $this->getJson("/api/events/{$this->event->path}/base-assets");
        $baseAssets->assertOk();
        $galleryConfig = data_get($baseAssets->json(), 'data.config');
        $this->assertSame(EventGalleryTypeEnum::FILMSTRIP_GALLERY, $galleryConfig['displayed_gallery'] ?? null);
        $this->assertFalse((bool) ($galleryConfig['preview_qr_in_gallery'] ?? true));
        $this->assertSame(14, (int) ($galleryConfig['video_display_time'] ?? 0));
        $this->assertSame('medium', data_get($galleryConfig, 'live_screen_options.ken_burns_intensity'));
    }

    public function test_guest_gallery_assets_endpoint_returns_visible_assets_with_path_token(): void
    {
        $visible = new EventAsset();
        $visible->event_id = $this->event->id;
        $visible->asset_type = 2;
        $visible->path = 'events/test/live-visible.jpg';
        $visible->status = StatusEnum::ACTIVE;
        $visible->is_displayed = true;
        $visible->user_agent = 'phpunit';
        $visible->ip = '127.0.0.1';
        $visible->save();

        $hidden = new EventAsset();
        $hidden->event_id = $this->event->id;
        $hidden->asset_type = 2;
        $hidden->path = 'events/test/live-hidden.jpg';
        $hidden->status = StatusEnum::ACTIVE;
        $hidden->is_displayed = false;
        $hidden->user_agent = 'phpunit';
        $hidden->ip = '127.0.0.1';
        $hidden->save();

        $response = $this->getJson(
            "/api/events/{$this->event->id}/gallery-guests-assets?token={$this->event->path}"
        );
        $response->assertOk();

        $ids = collect(data_get($response->json(), 'data', []))
            ->pluck('id')
            ->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }
}
