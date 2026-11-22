<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventHub;
use App\Models\User;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gen-2 templates (classic … party) + blocks[].variant. Unknown variants never 422.
 */
class EventHubVariantTest extends TestCase
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
        $this->user->last_name = 'Variant';
        $this->user->email = 'hub-variant-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'hub-variant-' . Str::lower(Str::random(10));
        $this->event->name = 'Hub Variant Event';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    private function defaults(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/gen2_default_hub_configs.json')), true);
    }

    private function putHub(array $hub_config)
    {
        return $this->actingAs($this->user, 'api')->putJson("/api/events/{$this->event->id}/hub", [
            'slug'         => null,
            'is_published' => false,
            'hub_config'   => $hub_config,
        ]);
    }

    private function savedBlock(string $id): array
    {
        $hub = EventHub::where('event_id', $this->event->id)->firstOrFail();

        return collect($hub->hub_config['blocks'])->firstWhere('id', $id);
    }

    private function withVariant(array $config, string $block_id, mixed $variant): array
    {
        foreach ($config['blocks'] as $i => $block) {
            if ($block['id'] === $block_id) {
                $config['blocks'][$i]['variant'] = $variant;
            }
        }

        return $config;
    }

    public function test_every_gen2_template_saves_its_default_config(): void
    {
        $defaults = $this->defaults();
        $this->assertCount(9, $defaults);

        foreach ($defaults as $template_id => $config) {
            $this->putHub($config)->assertOk();
            $this->assertSame($template_id, EventHub::where('event_id', $this->event->id)->first()->hub_config['template_id']);
        }
    }

    public function test_valid_variant_is_saved_and_read_back(): void
    {
        $config = $this->withVariant($this->defaults()['classic'], 'gallery', 'polaroid');
        $this->putHub($config)->assertOk();

        $this->assertSame('polaroid', $this->savedBlock('gallery')['variant']);
    }

    public function test_unknown_or_non_string_variant_saves_as_null(): void
    {
        foreach (['zzz', 5] as $bad) {
            $config = $this->withVariant($this->defaults()['night'], 'hero', $bad);
            $this->putHub($config)->assertOk();
            $this->assertNull($this->savedBlock('hero')['variant']);
        }
    }

    public function test_variant_on_a_block_without_variants_becomes_null(): void
    {
        $config = $this->withVariant($this->defaults()['classic'], 'story', 'split');
        $this->putHub($config)->assertOk();

        $this->assertNull($this->savedBlock('story')['variant']);
    }

    public function test_switch_from_old_template_to_classic_with_all_blocks(): void
    {
        // A wedding-1 page switched to classic keeps its wedding-1-only blocks (PEOPLE, SNAPSHARE_CTA).
        $config = $this->defaults()['classic'];
        $footer = array_pop($config['blocks']);
        $config['blocks'][] = ['id' => 'couple', 'type' => 'PEOPLE', 'enabled' => true, 'order' => 0,
            'data' => ['heading' => 'עמית וגל', 'items' => [['initials' => 'ע', 'name' => 'עמית', 'role' => 'חתן']]]];
        $config['blocks'][] = ['id' => 'snapshare_cta', 'type' => 'SNAPSHARE_CTA', 'enabled' => true, 'order' => 0,
            'data' => ['button_text' => 'העלו תמונות', 'subtitle' => 'בלי אפליקציה']];
        $config['blocks'][] = $footer;
        foreach ($config['blocks'] as $i => $block) {
            $config['blocks'][$i]['order'] = $i + 1;
        }
        $config = $this->withVariant($config, 'schedule', 'cards');
        $this->putHub($config)->assertOk();
        $this->assertSame('cards', $this->savedBlock('schedule')['variant']);
    }

    public function test_public_payload_includes_variant(): void
    {
        $config = $this->withVariant($this->defaults()['pastel'], 'hero', 'full');
        $slug = 'hub-variant-' . Str::lower(Str::random(8));
        $this->actingAs($this->user, 'api')->putJson("/api/events/{$this->event->id}/hub", [
            'slug'         => $slug,
            'is_published' => true,
            'hub_config'   => $config,
        ])->assertOk();

        $response = $this->getJson("/api/public/hubs/{$slug}");
        $response->assertOk();
        $blocks = collect($response->json('data.blocks') ?? []);
        $this->assertSame('full', $blocks->firstWhere('id', 'hero')['variant'] ?? null);
    }
}
