<?php

namespace App\Services\Auth;

use Exception;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Response;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Users\LoginService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use App\Services\Enums\AuthProviderEnum;
use Laravel\Socialite\Facades\Socialite;
use App\Services\Helpers\MaintenanceService;

class GoogleAuthService
{
    private const CACHE_PREFIX = 'google_auth_code:';

    public function redirectToGoogle(string $feRedirect, ?string $postLoginRedirect)
    {
        $this->validateRedirectOrigin($feRedirect);

        $state = Crypt::encryptString(json_encode([
            'redirect' => $feRedirect,
            'post_login_redirect' => $postLoginRedirect,
            'nonce' => Str::random(40),
            'exp' => now()->addSeconds(config('google.state_ttl'))->timestamp,
        ]));

        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }

    public function handleCallback(?string $googleCode, ?string $state, ?string $oauthError): string
    {
        $stateData = $this->parseState($state);
        $feRedirect = $stateData['redirect'] ?? $this->defaultFeRedirect();
        $postLoginRedirect = $stateData['post_login_redirect'] ?? null;

        if ($oauthError || !$googleCode) {
            return $this->buildFeRedirectUrl($feRedirect, ['error' => 'access_denied'], $postLoginRedirect);
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            $user = $this->resolveUser($googleUser);
            $this->assertUserCanLogin($user);

            $authCode = Str::random(64);
            Cache::put(self::CACHE_PREFIX . $authCode, $user->id, config('google.auth_code_ttl'));

            return $this->buildFeRedirectUrl($feRedirect, ['code' => $authCode], $postLoginRedirect);
        } catch (Exception $ex) {
            Log::warning('Google OAuth callback failed', [
                'message' => $ex->getMessage(),
                'fe_redirect' => $feRedirect,
            ]);

            return $this->buildFeRedirectUrl($feRedirect, ['error' => 'access_denied'], $postLoginRedirect);
        }
    }

    public function exchangeCode(string $code): object
    {
        $userId = Cache::pull(self::CACHE_PREFIX . $code);

        if (!$userId) {
            Log::warning('Google auth code exchange failed: code missing or already used', [
                'code_prefix' => substr($code, 0, 8),
            ]);

            throw new Exception(MessagesEnum::GOOGLE_AUTH_CODE_INVALID, Response::HTTP_UNAUTHORIZED);
        }

        $user = User::find($userId);

        if (!$user) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND, Response::HTTP_UNAUTHORIZED);
        }

        return (new LoginService())->buildResponseForUser($user)->getResponse();
    }

    public function validateRedirectOrigin(string $url): void
    {
        $origin = $this->parseOrigin($url);
        $allowed = config('google.allowed_redirect_origins', []);

        foreach ($allowed as $allowedOrigin) {
            if ($origin === rtrim($allowedOrigin, '/')) {
                return;
            }
        }

        throw new Exception(MessagesEnum::GOOGLE_AUTH_INVALID_REDIRECT, Response::HTTP_BAD_REQUEST);
    }

    private function resolveUser($googleUser): User
    {
        $googleId = $googleUser->getId();
        $email = $googleUser->getEmail();

        if (!$email) {
            throw new Exception(MessagesEnum::GOOGLE_AUTH_CODE_INVALID);
        }

        $user = User::where('google_id', $googleId)->first();
        if ($user) {
            return $user;
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            if ($user->google_id && $user->google_id !== $googleId) {
                throw new Exception(MessagesEnum::GOOGLE_ACCOUNT_CONFLICT);
            }

            $user->google_id = $googleId;
            if ($user->isPending()) {
                $user->status = StatusEnum::ACTIVE;
            }
            $user->save();

            return $user;
        }

        $user = new User();
        $user->role_id = RoleEnum::USER_ID;
        $user->first_name = $googleUser->user['given_name'] ?? '';
        $user->last_name = $googleUser->user['family_name'] ?? '';
        $user->email = $email;
        $user->google_id = $googleId;
        $user->auth_provider = AuthProviderEnum::GOOGLE;
        $user->password = null;
        $user->status = StatusEnum::ACTIVE;
        $user->save();

        return $user;
    }

    private function assertUserCanLogin(User $user): void
    {
        if (!$user->isActive()) {
            throw new Exception(MessagesEnum::USER_LOGIN_UNAUTHORIZED, Response::HTTP_UNAUTHORIZED);
        }

        if (MaintenanceService::isActive() && !$user->isAdmin()) {
            throw new Exception(MessagesEnum::MAINTENANCE_VALIDATION, Response::HTTP_UNAUTHORIZED);
        }
    }

    private function parseState(?string $state): array
    {
        if (!$state) {
            return [];
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true);

            if (!$payload || !isset($payload['exp']) || $payload['exp'] < now()->timestamp) {
                throw new Exception(MessagesEnum::GOOGLE_AUTH_STATE_INVALID);
            }

            if (isset($payload['redirect'])) {
                $this->validateRedirectOrigin($payload['redirect']);
            }

            return $payload;
        } catch (Exception $ex) {
            if ($ex->getMessage() === MessagesEnum::GOOGLE_AUTH_INVALID_REDIRECT) {
                throw $ex;
            }

            throw new Exception(MessagesEnum::GOOGLE_AUTH_STATE_INVALID);
        }
    }

    private function parseOrigin(string $url): string
    {
        $parts = parse_url($url);

        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            throw new Exception(MessagesEnum::GOOGLE_AUTH_INVALID_REDIRECT, Response::HTTP_BAD_REQUEST);
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function buildFeRedirectUrl(string $feRedirect, array $params, ?string $postLoginRedirect): string
    {
        if ($postLoginRedirect) {
            $params['post_login_redirect'] = $postLoginRedirect;
        }

        $separator = str_contains($feRedirect, '?') ? '&' : '?';

        return $feRedirect . $separator . http_build_query($params);
    }

    private function defaultFeRedirect(): string
    {
        return rtrim(config('app.client_url'), '/') . '/auth/google/callback';
    }
}
