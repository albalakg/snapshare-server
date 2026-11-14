<?php

namespace App\Http\Controllers;

use App\Http\Requests\HubAssetsIndexRequest;
use App\Http\Requests\HubSlugCheckRequest;
use App\Http\Requests\PublishEventHubRequest;
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

    public function publish(int $event_id, PublishEventHubRequest $request)
    {
        try {
            $service = new EventHubService();
            $response = $service->publishHub($event_id, Auth::user()->id, $request->validated());

            return $this->successResponse(MessagesEnum::EVENT_HUB_PUBLISHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function checkSlug(int $event_id, HubSlugCheckRequest $request)
    {
        try {
            $service = new EventHubService();
            $validated = $request->validated();
            $response = $service->checkSlug($event_id, Auth::user()->id, $validated['slug'] ?? null);

            return $this->successResponse(MessagesEnum::EVENT_HUB_SLUG_CHECKED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function assets(int $event_id, HubAssetsIndexRequest $request)
    {
        try {
            $service = new EventHubService();
            $validated = $request->validated();
            $response = $service->listHubAssets(
                $event_id,
                Auth::user()->id,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 24),
            );

            return $this->successResponse(MessagesEnum::EVENT_HUB_ASSETS_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
