<?php

namespace App\Http\Controllers;

use App\Services\Enums\MessagesEnum;
use App\Services\EventHub\EventHubService;
use Exception;
use Illuminate\Http\Response;

class PublicEventHubController extends Controller
{
    public function show(string $slug)
    {
        try {
            $service = new EventHubService();
            $response = $service->getPublicHub($slug);

            return $this->successResponse(MessagesEnum::EVENT_HUB_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
