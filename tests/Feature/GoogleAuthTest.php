<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Support\Facades\Cache;
use App\Services\Auth\GoogleAuthService;
use App\Services\Enums\AuthProviderEnum;

class GoogleAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.client_url' => 'http://localhost:8080',
            'google.allowed_redirect_origins' => ['http://localhost:8080'],
            'google.auth_code_ttl' => 60,
        ]);
    }

    public function test_google_redirect_rejects_invalid_redirect_origin(): void
    {
        $response = $this->getJson(
            '/api/auth/google/redirect?redirect=' . urlencode('https://evil.com/auth/google/callback')
        );

        $response->assertStatus(400);
    }

    public function test_google_exchange_rejects_invalid_code(): void
    {
        $response = $this->postJson('/api/auth/google/callback', [
            'code' => 'invalid-code',
        ]);

        $response->assertStatus(401);
    }

    public function test_google_exchange_rejects_reused_code(): void
    {
        $user = new User();
        $user->role_id = RoleEnum::USER_ID;
        $user->first_name = 'Test';
        $user->last_name = 'User';
        $user->email = 'google-exchange-' . uniqid() . '@example.com';
        $user->password = 'Password1';
        $user->status = StatusEnum::ACTIVE;
        $user->save();

        $code = 'reusable-test-code-' . uniqid();
        Cache::put('google_auth_code:' . $code, $user->id, 60);

        $first = $this->postJson('/api/auth/google/callback', ['code' => $code]);
        $first->assertStatus(200);
        $first->assertJsonPath('data.user.token', fn ($token) => is_string($token) && $token !== '');
        $first->assertJsonPath('data.user.expired_at', fn ($value) => is_string($value) && $value !== '');

        $this->postJson('/api/auth/google/callback', ['code' => $code])->assertStatus(401);

        $user->delete();
    }

    public function test_validate_redirect_origin_allows_configured_client_url(): void
    {
        $service = app(GoogleAuthService::class);

        $service->validateRedirectOrigin('http://localhost:8080/auth/google/callback');

        $this->assertTrue(true);
    }

    public function test_validate_redirect_origin_rejects_unknown_origin(): void
    {
        $service = app(GoogleAuthService::class);

        $this->expectException(\Exception::class);

        $service->validateRedirectOrigin('https://evil.com/auth/google/callback');
    }

    public function test_forgot_password_rejects_google_only_user(): void
    {
        $user = new User();
        $user->role_id = RoleEnum::USER_ID;
        $user->first_name = 'Google';
        $user->last_name = 'User';
        $user->email = 'google-only-' . uniqid() . '@example.com';
        $user->google_id = 'google-' . uniqid();
        $user->auth_provider = AuthProviderEnum::GOOGLE;
        $user->password = null;
        $user->status = StatusEnum::ACTIVE;
        $user->save();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertStatus(400);

        $user->delete();
    }
}
