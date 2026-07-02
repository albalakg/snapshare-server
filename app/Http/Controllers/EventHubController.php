<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEventHubRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\EventHub\EventHubService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class EventHubController extends Controller
{
    public function show(int $event_id)
    {
        try {
            $service = new EventHubService();
            $response = $service->getAdminHub($event_id, Auth::user()->id);

            return $this->successResponse(MessagesEnum::EVENT_HUB_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function update(int $event_id, UpdateEventHubRequest $request)
    {
        try {
            $service = new EventHubService();
            $response = $service->updateHub($event_id, Auth::user()->id, $request->validated());

            return $this->successResponse(MessagesEnum::EVENT_HUB_UPDATED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
