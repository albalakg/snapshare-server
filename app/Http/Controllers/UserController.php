<?php

namespace App\Http\Controllers;

use App\Services\Helpers\MailService;
use Exception;
use App\Services\Users\UserService;
use App\Services\Enums\MessagesEnum;
use Illuminate\Support\Facades\Auth;
use App\Services\Events\EventService;
use App\Services\Orders\StoreService;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserPasswordRequest;

class UserController extends Controller
{
    public function update(UpdateUserRequest $request)
    {
        try {
            $user_service = new UserService();
            $response = $user_service->update($request->validated(), Auth::user()->id);
            return $this->successResponse(MessagesEnum::USER_UPDATED_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
    
    public function updatePassword(UpdateUserPasswordRequest $request)
    {
        try {
            $user_service = new UserService();
            $user_service->changePassword($request->validated(), Auth::user()->id);
            return $this->successResponse(MessagesEnum::USER_UPDATED_PASSWORD_SUCCESS);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function delete()
    {
        try {
            $user_service = new UserService(new MailService, new EventService());
            $user_service->delete(Auth::user()->id);
            return $this->successResponse(MessagesEnum::USER_DELETED_SUCCESS);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function deleteByAdmin(int $user_id)
    {
        try {
            $user_service = new UserService();
            $user_service->delete($user_id, Auth::user()->id);
            return $this->successResponse(MessagesEnum::USER_DELETED_SUCCESS);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function get()
    {
        try {
            $user_service = new UserService();
            $response = $user_service->get();
            return $this->successResponse(MessagesEnum::USERS_FETCHED_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function find(int $user_id)
    {
        try {
            $user_service = new UserService();
            $response = $user_service->find($user_id);
            return $this->successResponse(MessagesEnum::USER_FOUND_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
    
    public function profile()
    {
        try {
            $user_service = new UserService(null , new EventService(), new StoreService);
            $response = $user_service->getProfile(Auth::user());
            return $this->successResponse(MessagesEnum::USER_FOUND_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
      
    public function logout()
    {
        try {
            $user_service = new UserService();
            $response = $user_service->logout(Auth::user());
            return $this->successResponse(MessagesEnum::LOGOUT_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
}
