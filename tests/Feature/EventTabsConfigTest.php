<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAsset;
use App\Models\EventConfig;
use App\Models\User;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventTabsConfigTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User();
        $this->user->role_id = RoleEnum::USER_ID;
        $this->user->first_name = 'Tabs';
        $this->user->last_name = 'Host';
        $this->user->email = 'event-tabs-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'event-tabs-' . Str::lower(Str::random(10));
        $this->event->name = 'Event Tabs Test';
        $this->event->status = StatusEnum::PENDING;
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
                'displayed_gallery' => 'EventGallerySingle',
                'video_upload_enabled' => true,
                'preview_link_to_album_page_from_upload_page' => true,
            ]
        );
    }

    public function test_update_persists_event_type_and_new_config_fields(): void
    {
        $response = $this->actingAs($this->user, 'api')->post(
            "/api/events/{$this->event->id}/update",
            [
                'name' => 'חתונה מעודכנת',
                'event_type' => 'wedding',
                'config' => [
                    'welcome_text' => 'ברוכים הבאים',
                    'image_display_time' => 5,
                    'video_display_time' => 12,
                    'manual_photo_approval' => 'true',
                    'preview_qr_in_gallery' => 'false',
                ],
            ]
        );

        $response->assertOk();
        $this->event->refresh();
        $config = EventConfig::where('event_id', $this->event->id)->first();

        $this->assertSame('wedding', $this->event->event_type);
        $this->assertSame('חתונה מעודכנת', $this->event->name);
        $this->assertSame('ברוכים הבאים', $config->welcome_text);
        $this->assertSame(5, (int) $config->image_display_time);
        $this->assertSame(12, (int) $config->video_display_time);
        $this->assertTrue((bool) $config->manual_photo_approval);
        $this->assertFalse((bool) $config->preview_qr_in_gallery);
    }

    public function test_partial_config_update_does_not_wipe_other_fields(): void
    {
        $config = EventConfig::where('event_id', $this->event->id)->firstOrFail();
        $config->welcome_text = 'טקסט מקורי';
        $config->manual_photo_approval = true;
        $config->preview_guests_assets_in_gallery = true;
        $config->video_upload_enabled = true;
        $config->image_display_time = 7;
        $config->video_display_time = 15;
        $config->save();

        $response = $this->actingAs($this->user, 'api')->post(
            "/api/events/{$this->event->id}/update",
            [
                'config' => [
                    'preview_site_display_name' => 'false',
                    'image_display_time' => 4,
                ],
            ]
        );

        $response->assertOk();
        $config->refresh();

        $this->assertFalse((bool) $config->preview_site_display_name);
        $this->assertSame(4, (int) $config->image_display_time);
        $this->assertSame('טקסט מקורי', $config->welcome_text);
        $this->assertTrue((bool) $config->manual_photo_approval);
        $this->assertTrue((bool) $config->preview_guests_assets_in_gallery);
        $this->assertTrue((bool) $config->video_upload_enabled);
        $this->assertSame(15, (int) $config->video_display_time);
        $this->assertTrue((bool) $config->preview_site_display_date);
    }

    public function test_favorite_and_approve_pending_assets(): void
    {
        $asset = new EventAsset();
        $asset->event_id = $this->event->id;
        $asset->asset_type = 2;
        $asset->path = 'events/test/sample.jpg';
        $asset->status = StatusEnum::PENDING;
        $asset->is_displayed = false;
        $asset->is_favorite = false;
        $asset->user_agent = 'phpunit';
        $asset->ip = '127.0.0.1';
        $asset->save();

        $fav = $this->actingAs($this->user, 'api')->post(
            "/api/events/{$this->event->id}/assets/favorite",
            ['assets' => [$asset->id], 'is_favorite' => true]
        );
        $fav->assertOk();
        $asset->refresh();
        $this->assertTrue((bool) $asset->is_favorite);

        $approve = $this->actingAs($this->user, 'api')->post(
            "/api/events/{$this->event->id}/assets/unblock",
            ['assets' => [$asset->id]]
        );
        $approve->assertOk();
        $asset->refresh();
        $this->assertSame(StatusEnum::ACTIVE, (int) $asset->status);
        $this->assertTrue((bool) $asset->is_displayed);
    }
}
