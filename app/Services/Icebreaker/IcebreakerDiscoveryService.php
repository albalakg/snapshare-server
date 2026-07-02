<?php

namespace App\Services\Icebreaker;

use App\Models\IcebreakerProfile;
use App\Services\Enums\MatchIntentEnum;
use Illuminate\Support\Facades\DB;

class IcebreakerDiscoveryService
{
    public function __construct(
        private ?IcebreakerConfigService $config_service = null,
        private ?IcebreakerProfileService $profile_service = null,
    ) {
        $this->config_service ??= new IcebreakerConfigService();
        $this->profile_service ??= new IcebreakerProfileService();
    }

    public function discover(int $event_id, string $session_token, int $limit = 20, ?string $intent_override = null): array
    {
        $this->config_service->assertFeatureActive($event_id);
        $my_profile = $this->profile_service->resolveActiveProfile($event_id, $session_token);

        $intent_id = $intent_override
            ? MatchIntentEnum::idFromKey($intent_override)
            : (int) $my_profile->primary_intent;

        $fetch_limit = $limit + 1;

        $query = IcebreakerProfile::query()
            ->select(['id', 'display_name', 'avatar_url', 'bio', 'primary_intent', 'gender', 'target_genders'])
            ->where('event_id', $event_id)
            ->where('is_active', true)
            ->where('id', '!=', $my_profile->id)
            ->where('primary_intent', $intent_id)
            ->whereNotExists(function ($sub) use ($my_profile) {
                $sub->select(DB::raw(1))
                    ->from('icebreaker_interactions')
                    ->whereColumn('icebreaker_interactions.target_profile_id', 'icebreaker_profiles.id')
                    ->where('icebreaker_interactions.actor_profile_id', $my_profile->id);
            });

        if ($intent_id === MatchIntentEnum::ROMANCE) {
            $my_gender = $my_profile->gender;
            $my_targets = $my_profile->target_genders ?? [];

            if ($my_gender && !empty($my_targets)) {
                $query->whereIn('gender', $my_targets)
                    ->where(function ($q) use ($my_gender) {
                        $q->whereNull('target_genders')
                            ->orWhereJsonContains('target_genders', $my_gender);
                    });
            }
        }

        $profiles = $query->orderBy('id')->limit($fetch_limit)->get();

        $has_more = $profiles->count() > $limit;
        $items = $profiles->take($limit)->map(fn (IcebreakerProfile $p) => [
            'profile_id'     => $p->id,
            'display_name'   => $p->display_name,
            'avatar_url'     => $p->avatar_url,
            'primary_intent' => MatchIntentEnum::keyFromId((int) $p->primary_intent),
            'bio'            => $p->bio,
        ])->values()->all();

        $response = [
            'profiles'  => $items,
            'has_more'  => $has_more,
        ];

        if (count($items) === 0) {
            $response['suggest_onboarding_broadcast'] = true;
        }

        return $response;
    }
}
