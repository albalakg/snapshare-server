<?php

namespace App\Services\Users;

use Exception;
use App\Models\User;
use Illuminate\Http\Response;
use App\Http\Requests\LoginRequest;
use Illuminate\Support\Facades\Auth;
use App\Services\Enums\MessagesEnum;
use App\Services\Helpers\MaintenanceService;

class LoginService
{
    private object $response;

    private bool $is_maintenance;

    private User $user;

    /**
     * Login to the application
     * 
     * @param string $email
     * @param string $password
     * @return void
     */
    public function __construct()
    {
        $this->is_maintenance = MaintenanceService::isActive();
    }

    /**
     * @param LoginRequest $request
     * @return self
     */
    public function attempt(LoginRequest $request): self
    {
        if (!Auth::attempt(['email' => $request->input('email'), 'password' => $request->input('password')])) {
            throw new Exception(MessagesEnum::INVALID_CREDENTIALS);
        }

        $this->user = Auth::user();
        $this->isUserAuthorizedToAccess();
        $this->buildUserDetails();
        return $this;
    }

    /**
     * @return object
     */
    public function getResponse(): object
    {
        return $this->response;
    }

    /**
     * Check if the user is authorized to login
     *
     * @return void
     */
    private function isUserAuthorizedToAccess()
    {
        if (!$this->user->isActive()) {
            throw new Exception(MessagesEnum::USER_LOGIN_UNAUTHORIZED, Response::HTTP_UNAUTHORIZED);
        }

        if ($this->is_maintenance && !$this->user->isAdmin()) {
            throw new Exception(MessagesEnum::MAINTENANCE_VALIDATION, Response::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * Build the user details object
     *
     * @return void
     */
    private function buildUserDetails()
    {
        $this->response = (object)[
            'user' => [
                'id'         => $this->user->id,
                'first_named' => config('session.lifetime'),
                'first_name' => $this->user->first_name,
                'last_name'  => $this->user->last_name,
                'email'      => $this->user->email,
                'role'       => $this->user->getRoleName(),
                'expired_at' => now()->addMinutes((int) config('session.lifetime')),
                'token'      => $this->setUserToken(),
                'subscription_name' => $this->user->order->subscription->name ?? '',
            ],
        ];
    }

    /**
     * Create and set the token
     *
     * @return string
     */
    private function setUserToken(): string
    {
        return $this->user->createToken(config('app.name'))->accessToken;
    }
}
