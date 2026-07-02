<?php

namespace App\Services\Icebreaker;

use App\Jobs\IcebreakerMatchNotificationJob;
use App\Models\IcebreakerInteraction;
use App\Models\IcebreakerMatch;
use App\Models\IcebreakerProfile;
use App\Services\Enums\InteractionTypeEnum;
use App\Services\Enums\MessagesEnum;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class IcebreakerMatchService
{
    public function __construct(
        private ?IcebreakerConfigService $config_service = null,
        private ?IcebreakerProfileService $profile_service = null,
    ) {
        $this->config_service ??= new IcebreakerConfigService();
        $this->profile_service ??= new IcebreakerProfileService();
    }

    public function interact(int $event_id, string $session_token, int $target_profile_id, string $action): array
    {
        $this->config_service->assertFeatureActive($event_id);
        $actor = $this->profile_service->resolveActiveProfile($event_id, $session_token);

        $action_id = strtoupper($action) === 'LIKE'
            ? InteractionTypeEnum::LIKE
            : InteractionTypeEnum::PASS;

        return DB::transaction(function () use ($event_id, $actor, $target_profile_id, $action_id) {
            $target = IcebreakerProfile::where('id', $target_profile_id)
                ->where('event_id', $event_id)
                ->lockForUpdate()
                ->first();

            if (!$target || !$target->is_active || $target->id === $actor->id) {
                throw new Exception(MessagesEnum::ICEBREAKER_INVALID_TARGET, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            try {
                IcebreakerInteraction::create([
                    'event_id'          => $event_id,
                    'actor_profile_id'  => $actor->id,
                    'target_profile_id' => $target->id,
                    'action'            => $action_id,
                ]);
            } catch (QueryException $ex) {
                if ($this->isDuplicateInteraction($ex)) {
                    throw new Exception(MessagesEnum::ICEBREAKER_ALREADY_INTERACTED, Response::HTTP_CONFLICT);
                }

                throw $ex;
            }

            if ($action_id === InteractionTypeEnum::PASS) {
                return ['match_found' => false];
            }

            $reciprocal = IcebreakerInteraction::where('actor_profile_id', $target->id)
                ->where('target_profile_id', $actor->id)
                ->where('action', InteractionTypeEnum::LIKE)
                ->lockForUpdate()
                ->first();

            if (!$reciprocal) {
                return ['match_found' => false];
            }

            $low_id = min($actor->id, $target->id);
            $high_id = max($actor->id, $target->id);

            $match = IcebreakerMatch::firstOrCreate(
                [
                    'profile_low_id'  => $low_id,
                    'profile_high_id' => $high_id,
                ],
                ['event_id' => $event_id],
            );

            IcebreakerMatchNotificationJob::dispatch($match->id);

            return [
                'match_found'   => true,
                'match_details' => [
                    'match_id'         => $match->id,
                    'display_name'     => $target->display_name,
                    'instagram_handle' => $target->instagram_handle,
                    'whatsapp_number'  => $target->whatsapp_number
                        ? '+' . ltrim((string) $target->whatsapp_number, '+')
                        : null,
                ],
            ];
        });
    }

    private function isDuplicateInteraction(QueryException $ex): bool
    {
        $errorCode = $ex->errorInfo[1] ?? null;

        return (int) $errorCode === 1062;
    }
}
