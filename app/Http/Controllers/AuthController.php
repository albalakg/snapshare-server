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
}
