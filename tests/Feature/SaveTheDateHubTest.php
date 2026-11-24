<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventHub;
use App\Models\User;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaveTheDateHubTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User();
        $this->user->role_id = RoleEnum::USER_ID;
        $this->user->first_name = 'Std';
        $this->user->last_name = 'Host';
        $this->user->email = 'std-hub-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'std-hub-' . Str::lower(Str::random(10));
        $this->event->name = 'Amit & Gal';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    public function test_put_persists_presentation_and_slice_on_admin_and_public(): void
    {
        $slug = 'std-pub-' . Str::lower(Str::random(8));
        $save = $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $this->config([
                    'presentation' => 'save_the_date',
                    'save_the_date' => $this->stdSlice(),
                ]),
            ]
        );
        $save->assertOk();

        $admin = $this->actingAs($this->user, 'api')->getJson("/api/events/{$this->event->id}/hub");
        $admin->assertOk();
        $admin->assertJsonPath('data.hub_config.presentation', 'save_the_date');
        $admin->assertJsonPath('data.hub_config.save_the_date.style', 'envelope');
        $admin->assertJsonPath('data.hub_config.save_the_date.content.a', 'עמית');
        $admin->assertJsonPath('data.hub_config.save_the_date.content.date', '2027-06-18');

        $public = $this->getJson("/api/public/hubs/{$slug}");
        $public->assertOk();
        $public->assertJsonPath('data.presentation', 'save_the_date');
        $public->assertJsonPath('data.save_the_date.style', 'envelope');
        $public->assertJsonPath('data.save_the_date.content.a', 'עמית');
        $public->assertJsonPath('data.template_id', 'wedding-1');
        $this->assertArrayHasKey('theme', $public->json('data'));
        $this->assertArrayHasKey('blocks', $public->json('data'));
        $this->assertArrayNotHasKey('user_id', $public->json('data') ?? []);
    }

    public function test_put_without_presentation_keeps_existing_landing_and_draft_slice(): void
    {
        $slug = 'std-draft-' . Str::lower(Str::random(8));
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $this->config([
                    'presentation' => 'landing',
                    'save_the_date' => $this->stdSlice(['style' => 'calendar']),
                ]),
            ]
        )->assertOk();

        // Landing builder-style PUT: blocks only, no presentation / slice.
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $this->wedding1Config(),
            ]
        )->assertOk();

        $admin = $this->actingAs($this->user, 'api')->getJson("/api/events/{$this->event->id}/hub");
        $admin->assertJsonPath('data.hub_config.presentation', 'landing');
        $admin->assertJsonPath('data.hub_config.save_the_date.style', 'calendar');
        $admin->assertJsonPath('data.hub_config.save_the_date.content.a', 'עמית');

        $public = $this->getJson("/api/public/hubs/{$slug}");
        $public->assertOk();
        $public->assertJsonPath('data.presentation', 'landing');
        $this->assertArrayNotHasKey('save_the_date', $public->json('data'));
        $public->assertJsonPath('data.template_id', 'wedding-1');
    }

    public function test_style_change_keeps_content_and_other_style_opts(): void
    {
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'std-style-' . Str::lower(Str::random(6)),
                'is_published' => false,
                'hub_config' => $this->config([
                    'presentation' => 'landing',
                    'save_the_date' => $this->stdSlice([
                        'style' => 'envelope',
                        'style_opts' => [
                            'envelope' => ['accent' => 'bordeaux', 'liner' => true, 'confetti' => false],
                        ],
                    ]),
                ]),
            ]
        )->assertOk();

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'std-style-' . Str::lower(Str::random(6)),
                'is_published' => false,
                'hub_config' => $this->config([
                    'presentation' => 'landing',
                    'save_the_date' => [
                        'style' => 'neon',
                        'style_opts' => [
                            'neon' => ['accent' => 'pink', 'flicker' => true],
                        ],
                        'content' => [
                            'kind' => 'wedding',
                            'a' => 'עמית',
                            'b' => 'גל',
                            'title' => '',
                            'sub' => '',
                            'date' => '2027-06-18',
                            'time' => '19:30',
                            'venue' => 'גן הוורדים',
                            'city' => 'זכרון יעקב',
                            'line' => 'הזמנה רשמית תגיע בהמשך',
                            'photo' => '/save-the-date/rings.jpg',
                        ],
                    ],
                ]),
            ]
        )->assertOk();

        $admin = $this->actingAs($this->user, 'api')->getJson("/api/events/{$this->event->id}/hub");
        $admin->assertJsonPath('data.hub_config.save_the_date.style', 'neon');
        $admin->assertJsonPath('data.hub_config.save_the_date.content.a', 'עמית');
        $admin->assertJsonPath('data.hub_config.save_the_date.content.venue', 'גן הוורדים');
        $admin->assertJsonPath('data.hub_config.save_the_date.style_opts.envelope.accent', 'bordeaux');
        $admin->assertJsonPath('data.hub_config.save_the_date.style_opts.envelope.liner', true);
        $admin->assertJsonPath('data.hub_config.save_the_date.style_opts.neon.accent', 'pink');
    }

    public function test_unknown_style_normalizes_to_envelope_without_422(): void
    {
        $res = $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'std-unk-' . Str::lower(Str::random(6)),
                'is_published' => false,
                'hub_config' => $this->config([
                    'presentation' => 'save_the_date',
                    'save_the_date' => $this->stdSlice(['style' => 'not-a-real-style']),
                ]),
            ]
        );
        $res->assertOk();

        $admin = $this->actingAs($this->user, 'api')->getJson("/api/events/{$this->event->id}/hub");
        $admin->assertJsonPath('data.hub_config.save_the_date.style', 'envelope');
    }

    public function test_invalid_reserved_and_taken_slugs_still_rejected(): void
    {
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'admin',
                'is_published' => false,
                'hub_config' => $this->config(['presentation' => 'landing', 'save_the_date' => $this->stdSlice()]),
            ]
        )->assertStatus(422)->assertSee(MessagesEnum::EVENT_HUB_SLUG_RESERVED, false);

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'bad slug!!',
                'is_published' => false,
                'hub_config' => $this->config(['presentation' => 'landing', 'save_the_date' => $this->stdSlice()]),
            ]
        )->assertStatus(422)->assertSee(MessagesEnum::EVENT_HUB_INVALID_SLUG, false);

        $other = new Event();
        $other->user_id = $this->user->id;
        $other->path = 'std-taken-path-' . Str::lower(Str::random(8));
        $other->name = 'Other';
        $other->status = StatusEnum::ACTIVE;
        $other->save();
        $taken = 'std-taken-' . Str::lower(Str::random(6));
        EventHub::create([
            'event_id' => $other->id,
            'slug' => $taken,
            'is_published' => false,
            'hub_config' => $this->wedding1Config(),
        ]);

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $taken,
                'is_published' => false,
                'hub_config' => $this->config(['presentation' => 'landing', 'save_the_date' => $this->stdSlice()]),
            ]
        )->assertStatus(422)->assertSee(MessagesEnum::EVENT_HUB_SLUG_TAKEN, false);

        // Collision with events.path
        $path_event = new Event();
        $path_event->user_id = $this->user->id;
        $path_event->path = 'std-path-coll-' . Str::lower(Str::random(6));
        $path_event->name = 'Path Coll';
        $path_event->status = StatusEnum::ACTIVE;
        $path_event->save();

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $path_event->path,
                'is_published' => false,
                'hub_config' => $this->config(['presentation' => 'landing', 'save_the_date' => $this->stdSlice()]),
            ]
        )->assertStatus(422)->assertSee(MessagesEnum::EVENT_HUB_SLUG_TAKEN, false);
    }

    public function test_publish_without_slug_is_422_and_unpublished_public_is_404(): void
    {
        $no_slug = $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => null,
                'is_published' => true,
                'hub_config' => $this->config([
                    'presentation' => 'save_the_date',
                    'save_the_date' => $this->stdSlice(),
                ]),
            ]
        );
        $no_slug->assertStatus(422);
        $this->assertStringContainsString(MessagesEnum::EVENT_HUB_SLUG_REQUIRED, (string) $no_slug->json('message'));

        $slug = 'std-unpub-' . Str::lower(Str::random(8));
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => false,
                'hub_config' => $this->config([
                    'presentation' => 'save_the_date',
                    'save_the_date' => $this->stdSlice(),
                ]),
            ]
        )->assertOk();

        $gone = $this->getJson("/api/public/hubs/{$slug}");
        $gone->assertStatus(404);
        $this->assertStringContainsString(MessagesEnum::EVENT_HUB_NOT_FOUND, (string) $gone->json('message'));
    }

    public function test_graduation_keeps_slice_and_does_not_change_hero_title(): void
    {
        $slug = 'std-grad-' . Str::lower(Str::random(8));
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $this->config([
                    'presentation' => 'save_the_date',
                    'save_the_date' => $this->stdSlice(),
                ]),
            ]
        )->assertOk();

        $before = EventHub::where('event_id', $this->event->id)->firstOrFail();
        $hero_before = $before->hub_config['blocks'][0]['data']['title'] ?? null;
        // Existing sanitizer encodes & in block titles; graduation must not change them further.
        $this->assertNotEmpty($hero_before);

        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $this->config([
                    'presentation' => 'landing',
                    'save_the_date' => $this->stdSlice(),
                ]),
            ]
        )->assertOk();

        $after = EventHub::where('event_id', $this->event->id)->firstOrFail();
        $this->assertSame('landing', $after->hub_config['presentation']);
        $this->assertSame('envelope', $after->hub_config['save_the_date']['style']);
        $this->assertSame('עמית', $after->hub_config['save_the_date']['content']['a']);
        $this->assertSame($hero_before, $after->hub_config['blocks'][0]['data']['title']);
        $this->assertSame($before->hub_config['blocks'], $after->hub_config['blocks']);

        $public = $this->getJson("/api/public/hubs/{$slug}");
        $public->assertOk();
        $public->assertJsonPath('data.presentation', 'landing');
        $this->assertArrayNotHasKey('save_the_date', $public->json('data'));
    }

    public function test_sanitizer_strips_html_from_std_strings(): void
    {
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'std-xss-' . Str::lower(Str::random(6)),
                'is_published' => false,
                'hub_config' => $this->config([
                    'presentation' => 'landing',
                    'save_the_date' => $this->stdSlice([
                        'content' => [
                            'kind' => 'wedding',
                            'a' => '<script>alert(1)</script>עמית',
                            'b' => 'גל<script>alert(1)</script>',
                            'title' => '',
                            'sub' => '',
                            'date' => '2027-06-18',
                            'time' => '19:30',
                            'venue' => '<script>x</script>גן הוורדים',
                            'city' => 'זכרון',
                            'line' => 'שורה <script>x</script>תחתונה',
                            'photo' => null,
                        ],
                    ]),
                ]),
            ]
        )->assertOk();

        $admin = $this->actingAs($this->user, 'api')->getJson("/api/events/{$this->event->id}/hub");
        $a = $admin->json('data.hub_config.save_the_date.content.a');
        $b = $admin->json('data.hub_config.save_the_date.content.b');
        $venue = $admin->json('data.hub_config.save_the_date.content.venue');
        $line = $admin->json('data.hub_config.save_the_date.content.line');
        $this->assertStringNotContainsString('<script>', (string) $a);
        $this->assertStringNotContainsString('<script>', (string) $b);
        $this->assertStringNotContainsString('<script>', (string) $line);
        $this->assertStringContainsString('עמית', (string) $a);
        $this->assertStringContainsString('גל', (string) $b);
        $this->assertStringContainsString('הוורדים', (string) $venue);
    }

    public function test_put_with_slice_but_without_blocks_is_422(): void
    {
        $res = $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => 'std-noblocks-' . Str::lower(Str::random(6)),
                'is_published' => false,
                'hub_config' => [
                    'presentation' => 'save_the_date',
                    'save_the_date' => $this->stdSlice(),
                ],
            ]
        );
        $res->assertStatus(422);
    }

    public function test_string_photo_is_returned_on_public_payload(): void
    {
        $slug = 'std-photo-' . Str::lower(Str::random(8));
        $this->actingAs($this->user, 'api')->putJson(
            "/api/events/{$this->event->id}/hub",
            [
                'slug' => $slug,
                'is_published' => true,
                'hub_config' => $this->config([
                    'presentation' => 'save_the_date',
                    'save_the_date' => $this->stdSlice(),
                ]),
            ]
        )->assertOk();

        $public = $this->getJson("/api/public/hubs/{$slug}");
        $public->assertOk();
        $public->assertJsonPath('data.save_the_date.content.photo', '/save-the-date/rings.jpg');
    }

    private function stdSlice(array $overrides = []): array
    {
        $slice = [
            'style' => 'envelope',
            'style_opts' => [
                'envelope' => ['accent' => 'bordeaux', 'liner' => true, 'confetti' => true],
            ],
            'content' => [
                'kind' => 'wedding',
                'a' => 'עמית',
                'b' => 'גל',
                'title' => '',
                'sub' => '',
                'date' => '2027-06-18',
                'time' => '19:30',
                'venue' => 'גן הוורדים',
                'city' => 'זכרון יעקב',
                'line' => 'הזמנה רשמית תגיע בהמשך',
                'photo' => '/save-the-date/rings.jpg',
            ],
            'show' => [
                'countdown' => true,
                'calendar' => true,
                'whatsapp' => true,
                'line' => true,
                'venue' => true,
                'hebdate' => true,
                'footer' => true,
            ],
        ];

        return array_replace_recursive($slice, $overrides);
    }

    private function config(array $extra): array
    {
        return array_merge($this->wedding1Config(), $extra);
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
