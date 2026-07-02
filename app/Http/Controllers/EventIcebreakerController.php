<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscoverIcebreakerProfilesRequest;
use App\Http\Requests\IcebreakerInteractRequest;
use App\Http\Requests\UpsertIcebreakerProfileRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\Icebreaker\IcebreakerConfigService;
use App\Services\Icebreaker\IcebreakerDiscoveryService;
use App\Services\Icebreaker\IcebreakerMatchService;
use App\Services\Icebreaker\IcebreakerProfileService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventIcebreakerController extends Controller
{
    public function getConfig(int $event_id)
    {
        try {
            $service = new IcebreakerConfigService();
            $response = $service->getPublicConfig($event_id);

            return $this->successResponse(MessagesEnum::ICEBREAKER_CONFIG_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function upsertProfile(int $event_id, UpsertIcebreakerProfileRequest $request)
    {
        try {
            $service = new IcebreakerProfileService();
            $response = $service->upsertProfile(
                $event_id,
                $request->attributes->get('icebreaker_session_token'),
                $request->validated(),
            );

            return $this->successResponse(MessagesEnum::ICEBREAKER_PROFILE_CREATED, $response, Response::HTTP_CREATED);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function discover(int $event_id, DiscoverIcebreakerProfilesRequest $request)
    {
        try {
            $service = new IcebreakerDiscoveryService();
            $response = $service->discover(
                $event_id,
                $request->attributes->get('icebreaker_session_token'),
                $request->limit(),
                $request->input('intent'),
            );

            return $this->successResponse(MessagesEnum::ICEBREAKER_PROFILES_DISCOVERED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function interact(int $event_id, IcebreakerInteractRequest $request)
    {
        try {
            $service = new IcebreakerMatchService();
            $response = $service->interact(
                $event_id,
                $request->attributes->get('icebreaker_session_token'),
                (int) $request->input('target_profile_id'),
                $request->input('action'),
            );

            return $this->successResponse(MessagesEnum::ICEBREAKER_INTERACTION_RECORDED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function deactivateProfile(int $event_id, Request $request)
    {
        try {
            $service = new IcebreakerProfileService();
            $service->deactivateProfile(
                $event_id,
                $request->attributes->get('icebreaker_session_token'),
            );

            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
