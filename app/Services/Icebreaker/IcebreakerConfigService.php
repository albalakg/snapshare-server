<?php

namespace App\Services\Icebreaker;

use App\Models\IcebreakerConfig;
use App\Models\IcebreakerProfile;
use App\Services\Enums\IcebreakerStatusEnum;
use App\Services\Enums\MatchIntentEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Events\EventService;
use Exception;
use Illuminate\Http\Response;

class IcebreakerConfigService
{
    public function __construct(
        private ?EventService $event_service = null,
    ) {
        $this->event_service ??= new EventService();
    }

    public function getPublicConfig(int $event_id): array
    {
        $config = $this->findConfigOrFail($event_id);

        return $this->formatPublicConfig($config);
    }

    public function getAdminConfig(int $event_id, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $config = IcebreakerConfig::where('event_id', $event_id)->first();

        if (!$config) {
            return [
                'event_id'        => $event_id,
                'status'          => 'DISABLED',
                'allowed_intents' => ['ROMANCE', 'SOCIAL', 'CARPOOL'],
                'started_at'      => null,
                'ended_at'        => null,
                'purged_at'       => null,
            ];
        }

        return $this->formatAdminConfig($config);
    }

    public function updateConfig(int $event_id, int $user_id, array $data): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $status = (int) $data['status'];
        $allowed_intents = array_values(array_unique($data['allowed_intents']));

        if ($status === IcebreakerStatusEnum::ACTIVE && empty($allowed_intents)) {
            throw new Exception(MessagesEnum::ICEBREAKER_INTENT_NOT_ALLOWED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $config = IcebreakerConfig::firstOrNew(['event_id' => $event_id]);

        if ($config->exists) {
            $this->assertValidStatusTransition((int) $config->status, $status);
        }

        $config->status = $status;
        $config->allowed_intents = $allowed_intents;

        if ($status === IcebreakerStatusEnum::ACTIVE && !$config->started_at) {
            $config->started_at = now();
        }

        if ($status === IcebreakerStatusEnum::COOLDOWN && !$config->ended_at) {
            $config->ended_at = now();
        }

        $config->save();

        return $this->formatAdminConfig($config);
    }

    public function assertFeatureActive(int $event_id): IcebreakerConfig
    {
        $config = $this->findConfigOrFail($event_id);

        if ((int) $config->status !== IcebreakerStatusEnum::ACTIVE) {
            throw new Exception(MessagesEnum::ICEBREAKER_NOT_ACTIVE, Response::HTTP_FORBIDDEN);
        }

        return $config;
    }

    public function assertIntentAllowed(IcebreakerConfig $config, string $intent_key): void
    {
        $allowed = $config->allowed_intents ?? [];

        if (!in_array(strtoupper($intent_key), $allowed, true)) {
            throw new Exception(MessagesEnum::ICEBREAKER_INTENT_NOT_ALLOWED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function activateOnEventStart(int $event_id): void
    {
        $config = IcebreakerConfig::where('event_id', $event_id)->first();

        if (!$config || (int) $config->status !== IcebreakerStatusEnum::UPCOMING) {
            return;
        }

        $config->status = IcebreakerStatusEnum::ACTIVE;
        $config->started_at = $config->started_at ?? now();
        $config->save();
    }

    public function cooldownOnEventEnd(int $event_id): void
    {
        $config = IcebreakerConfig::where('event_id', $event_id)->first();

        if (!$config || (int) $config->status !== IcebreakerStatusEnum::ACTIVE) {
            return;
        }

        $config->status = IcebreakerStatusEnum::COOLDOWN;
        $config->ended_at = now();
        $config->save();
    }

    private function findConfigOrFail(int $event_id): IcebreakerConfig
    {
        $config = IcebreakerConfig::where('event_id', $event_id)->first();

        if (!$config) {
            throw new Exception(MessagesEnum::ICEBREAKER_CONFIG_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return $config;
    }

    private function assertValidStatusTransition(int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = [
            IcebreakerStatusEnum::DISABLED => [IcebreakerStatusEnum::UPCOMING, IcebreakerStatusEnum::ACTIVE],
            IcebreakerStatusEnum::UPCOMING => [IcebreakerStatusEnum::ACTIVE, IcebreakerStatusEnum::DISABLED],
            IcebreakerStatusEnum::ACTIVE   => [IcebreakerStatusEnum::COOLDOWN, IcebreakerStatusEnum::DISABLED],
            IcebreakerStatusEnum::COOLDOWN => [IcebreakerStatusEnum::ARCHIVED],
            IcebreakerStatusEnum::ARCHIVED => [],
        ];

        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new Exception(MessagesEnum::ICEBREAKER_INVALID_STATUS_TRANSITION, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function formatPublicConfig(IcebreakerConfig $config): array
    {
        return [
            'event_id'        => $config->event_id,
            'status'          => $this->statusKey((int) $config->status),
            'allowed_intents' => $config->allowed_intents ?? [],
            'stats'           => [
                'total_active_profiles' => IcebreakerProfile::where('event_id', $config->event_id)
                    ->where('is_active', true)
                    ->count(),
            ],
        ];
    }

    private function formatAdminConfig(IcebreakerConfig $config): array
    {
        return [
            'event_id'        => $config->event_id,
            'status'          => $this->statusKey((int) $config->status),
            'allowed_intents' => $config->allowed_intents ?? [],
            'started_at'      => $config->started_at?->toIso8601String(),
            'ended_at'        => $config->ended_at?->toIso8601String(),
            'purged_at'       => $config->purged_at?->toIso8601String(),
        ];
    }

    private function statusKey(int $status): string
    {
        return match ($status) {
            IcebreakerStatusEnum::DISABLED => 'DISABLED',
            IcebreakerStatusEnum::UPCOMING => 'UPCOMING',
            IcebreakerStatusEnum::ACTIVE   => 'ACTIVE',
            IcebreakerStatusEnum::COOLDOWN => 'COOLDOWN',
            IcebreakerStatusEnum::ARCHIVED => 'ARCHIVED',
            default                        => 'DISABLED',
        };
    }
}
