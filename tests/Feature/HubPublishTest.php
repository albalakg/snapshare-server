<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventHub;
use App\Models\User;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class HubPublishTest extends TestCase
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
        $this->user->last_name = 'Publish';
        $this->user->email = 'hub-publish-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'hub-publish-' . Str::lower(Str::random(10));
        $this->event->name = 'Hub Publish Event';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    public function test_publish_requires_slug(): void
    {
        $save = $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => null,
                'is_published' => false,
                'hub_config' => $this->wedding1Config(),
            ]
        );
        $save->assertOk();

        $no_slug = $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => true]
        );
        $no_slug->assertStatus(422);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_HUB_SLUG_REQUIRED,
            (string) $no_slug->json('message')
        );

        $other = new Event();
        $other->user_id = $this->user->id;
        $other->path = 'hub-unsaved-' . Str::lower(Str::random(10));
        $other->name = 'Unsaved Hub Event';
        $other->status = StatusEnum::ACTIVE;
        $other->save();

        $not_saved = $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$other->id}/hub/publish",
            ['is_published' => true, 'slug' => 'fresh-slug-' . Str::lower(Str::random(6))]
        );
        $not_saved->assertStatus(422);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_HUB_NOT_SAVED,
            (string) $not_saved->json('message')
        );
    }

    public function test_reserved_taken_invalid_slug_rejected_on_publish(): void
    {
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'draft-ok-' . Str::lower(Str::random(6)),
                'is_published' => false,
                'hub_config' => $this->wedding1Config(),
            ]
        )->assertOk();

        $reserved = $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => true, 'slug' => 'admin']
        );
        $reserved->assertStatus(422);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_HUB_SLUG_RESERVED,
            (string) $reserved->json('message')
        );

        $other_user = new User();
        $other_user->role_id = RoleEnum::USER_ID;
        $other_user->first_name = 'Other';
        $other_user->last_name = 'Host';
        $other_user->email = 'hub-publish-other-' . uniqid() . '@example.com';
        $other_user->password = 'Password1';
        $other_user->status = StatusEnum::ACTIVE;
        $other_user->save();

        $other_event = new Event();
        $other_event->user_id = $other_user->id;
        $other_event->path = 'hub-taken-' . Str::lower(Str::random(10));
        $other_event->name = 'Taken Slug Event';
        $other_event->status = StatusEnum::ACTIVE;
        $other_event->save();

        $taken_slug = 'taken-slug-' . Str::lower(Str::random(8));
        EventHub::create([
            'event_id' => $other_event->id,
            'slug' => $taken_slug,
            'is_published' => true,
            'hub_config' => $this->wedding1Config(),
        ]);

        $taken = $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => true, 'slug' => $taken_slug]
        );
        $taken->assertStatus(422);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_HUB_SLUG_TAKEN,
            (string) $taken->json('message')
        );

        $invalid = $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => true, 'slug' => 'bad slug!!']
        );
        $invalid->assertStatus(422);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_HUB_INVALID_SLUG,
            (string) $invalid->json('message')
        );
    }

    public function test_publish_then_public_get_200_with_template_id(): void
    {
        $slug = 'pub-hub-' . Str::lower(Str::random(8));

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => false,
                'hub_config' => $this->wedding1Config(),
            ]
        )->assertOk();

        $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => true]
        )->assertOk();

        $public = $this->getJson("/api/public/hubs/{$slug}");
        $public->assertOk();
        $public->assertJsonPath('data.template_id', 'wedding-1');
    }

    public function test_unpublish_makes_public_404(): void
    {
        $slug = 'unpub-hub-' . Str::lower(Str::random(8));

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $this->wedding1Config(),
            ]
        )->assertOk();

        $this->getJson("/api/public/hubs/{$slug}")->assertOk();

        $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => false]
        )->assertOk();

        $gone = $this->getJson("/api/public/hubs/{$slug}");
        $gone->assertStatus(404);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_HUB_NOT_FOUND,
            (string) $gone->json('message')
        );
    }

    public function test_non_owner_forbidden(): void
    {
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'owner-only-' . Str::lower(Str::random(6)),
                'is_published' => false,
                'hub_config' => $this->wedding1Config(),
            ]
        )->assertOk();

        $intruder = new User();
        $intruder->role_id = RoleEnum::USER_ID;
        $intruder->first_name = 'Intruder';
        $intruder->last_name = 'User';
        $intruder->email = 'hub-intruder-' . uniqid() . '@example.com';
        $intruder->password = 'Password1';
        $intruder->status = StatusEnum::ACTIVE;
        $intruder->save();

        $response = $this->actingAs($intruder, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => true]
        );
        $response->assertStatus(400);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_NOT_AUTHORIZED,
            (string) $response->json('message')
        );
    }

    public function test_slug_check_responses(): void
    {
        $own_slug = 'own-slug-' . Str::lower(Str::random(8));
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $own_slug,
                'is_published' => false,
                'hub_config' => $this->wedding1Config(),
            ]
        )->assertOk();

        $available = $this->actingAs($this->user, 'api')->getJson(
            "/api/events/{$this->event->id}/hub/slug-check?slug=fresh-available-" . Str::lower(Str::random(6))
        );
        $available->assertOk();
        $available->assertJsonPath('data.available', true);
        $this->assertNull($available->json('data.reason'));

        $reserved = $this->actingAs($this->user, 'api')->getJson(
            "/api/events/{$this->event->id}/hub/slug-check?slug=admin"
        );
        $reserved->assertOk();
        $reserved->assertJsonPath('data.available', false);
        $reserved->assertJsonPath('data.reason', 'reserved');

        $other_event = new Event();
        $other_event->user_id = $this->user->id;
        $other_event->path = 'hub-slug-taken-' . Str::lower(Str::random(10));
        $other_event->name = 'Slug Taken';
        $other_event->status = StatusEnum::ACTIVE;
        $other_event->save();

        $taken_slug = 'already-taken-' . Str::lower(Str::random(6));
        EventHub::create([
            'event_id' => $other_event->id,
            'slug' => $taken_slug,
            'is_published' => false,
            'hub_config' => $this->wedding1Config(),
        ]);

        $taken = $this->actingAs($this->user, 'api')->getJson(
            "/api/events/{$this->event->id}/hub/slug-check?slug={$taken_slug}"
        );
        $taken->assertOk();
        $taken->assertJsonPath('data.available', false);
        $taken->assertJsonPath('data.reason', 'taken');

        $short = $this->actingAs($this->user, 'api')->getJson(
            "/api/events/{$this->event->id}/hub/slug-check?slug=ab"
        );
        $short->assertOk();
        $short->assertJsonPath('data.available', false);
        $short->assertJsonPath('data.reason', 'too_short');

        $own = $this->actingAs($this->user, 'api')->getJson(
            "/api/events/{$this->event->id}/hub/slug-check?slug={$own_slug}"
        );
        $own->assertOk();
        $own->assertJsonPath('data.available', true);
        $this->assertNull($own->json('data.reason'));
    }

    public function test_cache_cleared_on_publish_unpublish(): void
    {
        $slug = 'cache-hub-' . Str::lower(Str::random(8));
        $prefix = config('event_hub.cache_prefix', 'hub:slug:');
        $cache_key = $prefix . $slug;

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => false,
                'hub_config' => $this->wedding1Config(),
            ]
        )->assertOk();

        Cache::put($cache_key, ['stale' => true], 3600);
        $this->assertTrue(Cache::has($cache_key));

        $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => true]
        )->assertOk();
        $this->assertFalse(Cache::has($cache_key));

        $this->getJson("/api/public/hubs/{$slug}")->assertOk();
        $this->assertTrue(Cache::has($cache_key));

        Cache::put($cache_key, ['stale' => true], 3600);
        $this->actingAs($this->user, 'api')->patchJson(
            "/api/events/{$this->event->id}/hub/publish",
            ['is_published' => false]
        )->assertOk();
        $this->assertFalse(Cache::has($cache_key));
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
}
