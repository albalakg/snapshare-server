<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateIcebreakerConfigRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\Icebreaker\IcebreakerConfigService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class EventIcebreakerConfigController extends Controller
{
    public function getAdminConfig(int $event_id)
    {
        try {
            $service = new IcebreakerConfigService();
            $response = $service->getAdminConfig($event_id, Auth::user()->id);

            return $this->successResponse(MessagesEnum::ICEBREAKER_CONFIG_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function updateConfig(int $event_id, UpdateIcebreakerConfigRequest $request)
    {
        try {
            $service = new IcebreakerConfigService();
            $response = $service->updateConfig($event_id, Auth::user()->id, $request->validated());

            return $this->successResponse(MessagesEnum::ICEBREAKER_CONFIG_UPDATED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
