<?php

namespace App\Services\Icebreaker;

use App\Models\IcebreakerProfile;
use App\Services\Enums\MatchIntentEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Guests\PhoneNormalizer;
use App\Services\Moderation\ContentModerationService;
use App\Services\Moderation\ProfanityFilterService;
use Exception;
use Illuminate\Http\Response;

class IcebreakerProfileService
{
    public function __construct(
        private ?IcebreakerConfigService $config_service = null,
        private ?ContentModerationService $moderation_service = null,
        private ?ProfanityFilterService $profanity_service = null,
    ) {
        $this->config_service ??= new IcebreakerConfigService();
        $this->moderation_service ??= new ContentModerationService();
        $this->profanity_service ??= new ProfanityFilterService();
    }

    public function upsertProfile(int $event_id, string $session_token, array $data): array
    {
        $config = $this->config_service->assertFeatureActive($event_id);
        $this->config_service->assertIntentAllowed($config, $data['primary_intent']);

        $this->moderateProfileContent($data);

        $intent_id = MatchIntentEnum::idFromKey($data['primary_intent']);
        $whatsapp = null;

        if (!empty($data['whatsapp_number'])) {
            $whatsapp = PhoneNormalizer::validate($data['whatsapp_number']);
        }

        $profile = IcebreakerProfile::updateOrCreate(
            [
                'event_id'           => $event_id,
                'user_session_token' => $session_token,
            ],
            [
                'display_name'      => trim($data['display_name']),
                'avatar_url'        => $data['avatar_url'],
                'gender'            => $data['gender'] ?? null,
                'target_genders'    => $data['target_genders'] ?? null,
                'primary_intent'    => $intent_id,
                'bio'               => isset($data['bio']) ? trim($data['bio']) : null,
                'instagram_handle'  => $data['instagram_handle'] ?? null,
                'whatsapp_number'   => $whatsapp,
                'is_active'         => true,
            ],
        );

        return [
            'profile_id' => $profile->id,
            'status'     => 'ACTIVE',
        ];
    }

    public function deactivateProfile(int $event_id, string $session_token): void
    {
        $profile = IcebreakerProfile::where('event_id', $event_id)
            ->where('user_session_token', $session_token)
            ->first();

        if (!$profile) {
            throw new Exception(MessagesEnum::ICEBREAKER_PROFILE_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $profile->is_active = false;
        $profile->display_name = 'Deleted';
        $profile->bio = null;
        $profile->instagram_handle = null;
        $profile->whatsapp_number = null;
        $profile->avatar_url = '';
        $profile->save();
    }

    public function resolveActiveProfile(int $event_id, string $session_token): IcebreakerProfile
    {
        $profile = IcebreakerProfile::where('event_id', $event_id)
            ->where('user_session_token', $session_token)
            ->where('is_active', true)
            ->first();

        if (!$profile) {
            throw new Exception(MessagesEnum::ICEBREAKER_PROFILE_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $profile;
    }

    private function moderateProfileContent(array $data): void
    {
        if ($this->profanity_service->containsProfanity($data['display_name'])) {
            throw new Exception(MessagesEnum::ICEBREAKER_CONTENT_REJECTED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!empty($data['bio']) && $this->profanity_service->containsProfanity($data['bio'])) {
            throw new Exception(MessagesEnum::ICEBREAKER_CONTENT_REJECTED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $storage_path = $this->extractStoragePath($data['avatar_url']);

        if ($storage_path) {
            $result = $this->moderation_service->checkImage($storage_path);

            if (!$result['isValid']) {
                throw new Exception(MessagesEnum::ICEBREAKER_CONTENT_REJECTED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
    }

    private function extractStoragePath(string $avatar_url): ?string
    {
        $storage_url = rtrim((string) config('app.storage_url'), '/');

        if ($storage_url !== '' && str_starts_with($avatar_url, $storage_url)) {
            return ltrim(substr($avatar_url, strlen($storage_url)), '/');
        }

        $parsed = parse_url($avatar_url, PHP_URL_PATH);

        return $parsed ? ltrim($parsed, '/') : null;
    }
}
