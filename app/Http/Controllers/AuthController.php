<?php

namespace App\Http\Controllers;

use Exception;
use App\Http\Requests\LoginRequest;
use App\Services\Users\UserService;
use App\Http\Requests\SignupRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\Users\LoginService;
use Illuminate\Support\Facades\Auth;
use App\Services\Events\EventService;
use App\Services\Helpers\MailService;
use App\Http\Requests\UploadFileRequest;
use App\Http\Requests\ConfirmEmailRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\GoogleRedirectRequest;
use App\Http\Requests\GoogleAuthExchangeRequest;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        try {
            $login_service = new LoginService;
            $response = $login_service->attempt($request)->getResponse();
            return $this->successResponse(MessagesEnum::LOGIN_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function signup(SignupRequest $request)
    {
        try {
            $user_service = new UserService(new MailService);
            $created_user = $user_service->signup($request->validated());
            return $this->successResponse(MessagesEnum::SIGNUP_SUCCESS, $created_user);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        try {
            $user_service = new UserService;
            $user_service->resetPassword($request->token, $request->password);
            return $this->successResponse(MessagesEnum::RESET_PASSWORD);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        try {
            $user_service = new UserService(new MailService);
            $user_service->forgotPassword($request->email);
            return $this->successResponse(MessagesEnum::FORGOT_PASSWORD);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function confirmEmail(ConfirmEmailRequest $request)
    {
        try {
            $user_service = new UserService;
            $user_service->confirmEmail($request->email, $request->token);
            return $this->successResponse(MessagesEnum::CONFIRM_EMAIL);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function googleRedirect(GoogleRedirectRequest $request)
    {
        try {
            return app(GoogleAuthService::class)->redirectToGoogle(
                $request->input('redirect'),
                $request->input('post_login_redirect')
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function googleCallback(Request $request)
    {
        try {
            $redirectUrl = app(GoogleAuthService::class)->handleCallback(
                $request->query('code'),
                $request->query('state'),
                $request->query('error')
            );

            return redirect()->away($redirectUrl);
        } catch (Exception $ex) {
            $feRedirect = rtrim(config('app.client_url'), '/') . '/auth/google/callback';

            return redirect()->away($feRedirect . '?error=access_denied');
        }
    }

    public function googleExchange(GoogleAuthExchangeRequest $request)
    {
        try {
            $response = app(GoogleAuthService::class)->exchangeCode($request->input('code'));

            return $this->successResponse(MessagesEnum::LOGIN_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: 400);
        }
    }
}
