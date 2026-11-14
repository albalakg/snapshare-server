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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class HubConfigValidationTest extends TestCase
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
        $this->user->last_name = 'Config';
        $this->user->email = 'hub-config-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'hub-config-' . Str::lower(Str::random(10));
        $this->event->name = 'Hub Config Event';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    public function test_valid_config_for_several_templates(): void
    {
        foreach (['wedding-1', 'party-1', 'festival-1'] as $template_id) {
            $response = $this->putHub([
                'slug' => null,
                'is_published' => false,
                'hub_config' => $this->templateConfig($template_id),
            ]);
            $response->assertOk();
            $response->assertJsonPath('status', true);
        }
    }

    public function test_bad_template_id_rejected(): void
    {
        $config = $this->wedding1Config(['template_id' => 'not-a-template']);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]));
    }

    public function test_palette_not_of_template_rejected(): void
    {
        $config = $this->wedding1Config([
            'theme' => ['palette_id' => 'neon'],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]));
    }

    public function test_font_outside_allowlist_rejected(): void
    {
        $config = $this->wedding1Config([
            'theme' => ['font_family' => 'Comic Sans MS'],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]));
    }

    public function test_bad_scale_rejected(): void
    {
        $config = $this->wedding1Config([
            'theme' => ['scale' => 'XL'],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]));
    }

    public function test_section_type_not_allowed_for_template_rejected(): void
    {
        $config = $this->wedding1Config();
        $config['blocks'] = [
            $config['blocks'][0],
            $config['blocks'][1],
            $config['blocks'][2],
            [
                'id' => 'lineup1',
                'type' => 'LINEUP',
                'enabled' => true,
                'order' => 4,
                'data' => ['heading' => 'ליינאפ'],
            ],
            [
                'id' => 'footer1',
                'type' => 'FOOTER',
                'enabled' => true,
                'order' => 5,
                'data' => ['note' => 'SnapShare'],
            ],
        ];

        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]));
    }

    public function test_unknown_type_rejected(): void
    {
        $config = $this->wedding1Config();
        $config['blocks'][1]['type'] = 'NOT_A_REAL_BLOCK';

        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]));
    }

    public function test_missing_hero_or_footer_rejected(): void
    {
        $no_hero = $this->wedding1Config();
        $no_hero['blocks'] = array_values(array_filter(
            $no_hero['blocks'],
            fn ($b) => ($b['type'] ?? null) !== 'HERO_COUNTDOWN'
        ));
        foreach ($no_hero['blocks'] as $i => $block) {
            $no_hero['blocks'][$i]['order'] = $i + 1;
        }
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $no_hero,
        ]));

        $no_footer = $this->wedding1Config();
        $no_footer['blocks'] = array_values(array_filter(
            $no_footer['blocks'],
            fn ($b) => ($b['type'] ?? null) !== 'FOOTER'
        ));
        foreach ($no_footer['blocks'] as $i => $block) {
            $no_footer['blocks'][$i]['order'] = $i + 1;
        }
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $no_footer,
        ]));
    }

    public function test_hero_footer_enabled_false_forced_true(): void
    {
        $config = $this->wedding1Config();
        foreach ($config['blocks'] as $i => $block) {
            if (in_array($block['type'], ['HERO_COUNTDOWN', 'FOOTER'], true)) {
                $config['blocks'][$i]['enabled'] = false;
            }
        }

        $save = $this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]);
        $save->assertOk();

        $get = $this->actingAs($this->user, 'api')
            ->getJson("/api/events/{$this->event->id}/hub");
        $get->assertOk();

        $blocks = collect($get->json('data.hub_config.blocks'));
        $hero = $blocks->firstWhere('type', 'HERO_COUNTDOWN');
        $footer = $blocks->firstWhere('type', 'FOOTER');
        $this->assertTrue((bool) $hero['enabled']);
        $this->assertTrue((bool) $footer['enabled']);
    }

    public function test_rich_text_right_accepted(): void
    {
        $config = $this->wedding1Config();
        $config['blocks'] = [
            $config['blocks'][0],
            $config['blocks'][1],
            [
                'id' => 'rich1',
                'type' => 'RICH_TEXT',
                'enabled' => true,
                'order' => 3,
                'data' => [
                    'heading' => 'טקסט',
                    'body' => 'תוכן',
                    'alignment' => 'RIGHT',
                ],
            ],
            $config['blocks'][2],
            [
                'id' => 'footer1',
                'type' => 'FOOTER',
                'enabled' => true,
                'order' => 5,
                'data' => ['note' => 'SnapShare'],
            ],
        ];
        $config['blocks'][3]['order'] = 4;

        $response = $this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]);
        $response->assertOk();
    }

    public function test_free_form_url_image_slots_rejected(): void
    {
        $cover_url = $this->wedding1Config([
            'theme' => ['cover' => 'https://example.com/photo.jpg'],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $cover_url,
        ]));

        $accent = $this->wedding1Config([
            'theme' => ['accent_image_url' => 'https://example.com/accent.jpg'],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $accent,
        ]));

        $hero_url = $this->wedding1Config();
        $hero_url['blocks'][0]['data']['cover_image_url'] = 'https://example.com/hero.jpg';
        unset($hero_url['blocks'][0]['data']['cover']);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $hero_url,
        ]));
    }

    public function test_unknown_stock_key_and_art_album_00_rejected(): void
    {
        $unknown = $this->wedding1Config([
            'theme' => ['cover' => ['stock' => 'wedding-1/does-not-exist']],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $unknown,
        ]));

        $album00 = $this->wedding1Config([
            'theme' => ['cover' => ['stock' => 'art/album-00']],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $album00,
        ]));
    }

    public function test_valid_stock_key_accepted(): void
    {
        $config = $this->wedding1Config([
            'theme' => ['cover' => ['stock' => 'wedding-1/hero']],
        ]);
        $config['blocks'][0]['data']['cover'] = ['stock' => 'art/blush'];

        $response = $this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]);
        $response->assertOk();
    }

    public function test_asset_id_of_another_event_rejected(): void
    {
        $other = new Event();
        $other->user_id = $this->user->id;
        $other->path = 'hub-other-' . Str::lower(Str::random(10));
        $other->name = 'Other Event';
        $other->status = StatusEnum::ACTIVE;
        $other->save();

        $foreign = $this->createImageAsset($other->id);

        $config = $this->wedding1Config([
            'theme' => ['cover' => ['asset_id' => $foreign->id]],
        ]);

        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]));
    }

    public function test_blocked_or_hidden_asset_rejected(): void
    {
        $blocked = $this->createImageAsset($this->event->id, [
            'status' => StatusEnum::BLOCKED,
            'is_displayed' => false,
        ]);
        $config = $this->wedding1Config([
            'theme' => ['cover' => ['asset_id' => $blocked->id]],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]));

        $hidden = $this->createImageAsset($this->event->id, [
            'status' => StatusEnum::ACTIVE,
            'is_displayed' => false,
        ]);
        $config2 = $this->wedding1Config([
            'theme' => ['cover' => ['asset_id' => $hidden->id]],
        ]);
        $this->assertInvalidBlocks($this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config2,
        ]));
    }

    public function test_legacy_config_without_template_id_still_accepted(): void
    {
        $config = [
            'theme' => [
                'primary_color' => '#D4AF37',
                'background_type' => 'LIGHT',
                'font_family' => 'Rubik',
                'accent_image_url' => 'https://example.com/accent.jpg',
            ],
            'blocks' => [
                [
                    'id' => 'hero1',
                    'type' => 'HERO_COUNTDOWN',
                    'enabled' => true,
                    'order' => 1,
                    'data' => [
                        'title' => 'Legacy Event',
                        'event_date' => '2026-12-01',
                        'cover_image_url' => 'https://example.com/cover.jpg',
                    ],
                ],
                [
                    'id' => 'cta1',
                    'type' => 'SNAPSHARE_CTA',
                    'enabled' => true,
                    'order' => 2,
                    'data' => [
                        'button_text' => 'העלו תמונות',
                        'subtitle' => 'בלי אפליקציה',
                    ],
                ],
            ],
        ];

        $response = $this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]);
        $response->assertOk();
    }

    public function test_wishing_well_rsvp_store_no_extra_and_no_endpoint(): void
    {
        $config = $this->wedding1Config();
        $config['blocks'] = [
            $config['blocks'][0],
            [
                'id' => 'wish1',
                'type' => 'WISHING_WELL',
                'enabled' => true,
                'order' => 2,
                'data' => [
                    'title' => 'מתנות',
                    'message' => 'תודה',
                    'gift_label' => 'ביט',
                    'payment_link' => 'https://pay.example.com/gift',
                ],
            ],
            [
                'id' => 'rsvp1',
                'type' => 'RSVP',
                'enabled' => true,
                'order' => 3,
                'data' => [
                    'heading' => 'אישור הגעה',
                    'body' => 'נא לאשר',
                ],
            ],
            $config['blocks'][2],
            [
                'id' => 'footer1',
                'type' => 'FOOTER',
                'enabled' => true,
                'order' => 5,
                'data' => ['note' => 'SnapShare'],
            ],
        ];
        $config['blocks'][3]['order'] = 4;

        $response = $this->putHub([
            'slug' => null,
            'is_published' => false,
            'hub_config' => $config,
        ]);
        $response->assertOk();

        $get = $this->actingAs($this->user, 'api')
            ->getJson("/api/events/{$this->event->id}/hub");
        $get->assertOk();
        $wish = collect($get->json('data.hub_config.blocks'))->firstWhere('type', 'WISHING_WELL');
        $this->assertSame('מתנות', $wish['data']['title']);
        $this->assertSame('https://pay.example.com/gift', $wish['data']['payment_link']);

        $hub_wish_rsvp_posts = collect(Route::getRoutes())->filter(function ($route) {
            $uri = $route->uri();
            $methods = $route->methods();
            if (!in_array('POST', $methods, true) && !in_array('PUT', $methods, true)) {
                return false;
            }

            return (bool) preg_match('#(hubs?.*/(wish|wishes|rsvp)|/(wish|wishes|rsvp)/)#i', $uri);
        });
        $this->assertCount(0, $hub_wish_rsvp_posts);
    }

    private function putHub(array $payload)
    {
        return $this->actingAs($this->user, 'api')
            ->putJson("/api/events/{$this->event->id}/hub", $payload);
    }

    private function assertInvalidBlocks($response): void
    {
        $response->assertStatus(422);
        $this->assertStringContainsString(
            MessagesEnum::EVENT_HUB_INVALID_BLOCKS,
            (string) $response->json('message')
        );
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

    private function templateConfig(string $template_id): array
    {
        if ($template_id === 'wedding-1') {
            return $this->wedding1Config();
        }

        $meta = [
            'party-1' => [
                'font' => 'Rubik',
                'background_type' => 'DARK',
                'stock' => 'party-1/hero',
                'section' => [
                    'id' => 'about1',
                    'type' => 'ABOUT',
                    'data' => [
                        'heading' => 'אודות',
                        'body' => 'מסיבה',
                        'layout' => 'split',
                    ],
                ],
            ],
            'festival-1' => [
                'font' => 'Rubik',
                'background_type' => 'DARK',
                'stock' => 'festival-1/hero',
                'section' => [
                    'id' => 'about1',
                    'type' => 'ABOUT',
                    'data' => [
                        'heading' => 'אודות',
                        'body' => 'פסטיבל',
                        'layout' => 'center',
                    ],
                ],
            ],
        ];

        $info = $meta[$template_id];

        return [
            'template_id' => $template_id,
            'theme' => [
                'palette_id' => 'default',
                'background_type' => $info['background_type'],
                'font_family' => $info['font'],
                'scale' => 'M',
                'cover' => ['stock' => $info['stock']],
            ],
            'blocks' => [
                [
                    'id' => 'hero1',
                    'type' => 'HERO_COUNTDOWN',
                    'enabled' => true,
                    'order' => 1,
                    'data' => [
                        'title' => 'Test Event',
                        'event_date' => '2026-12-01',
                        'cover' => ['stock' => $info['stock']],
                    ],
                ],
                array_merge($info['section'], [
                    'enabled' => true,
                    'order' => 2,
                ]),
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
    }

    private function createImageAsset(int $event_id, array $opts = []): EventAsset
    {
        $asset = new EventAsset();
        $asset->event_id = $event_id;
        $asset->asset_type = EventAssetTypeEnum::IMAGE_ID;
        $asset->path = $opts['path'] ?? ('events/hub-test/' . Str::uuid() . '.jpg');
        $asset->status = $opts['status'] ?? StatusEnum::ACTIVE;
        $asset->is_displayed = $opts['is_displayed'] ?? true;
        $asset->user_agent = 'phpunit';
        $asset->ip = '127.0.0.1';
        $asset->save();

        return $asset;
    }
}
