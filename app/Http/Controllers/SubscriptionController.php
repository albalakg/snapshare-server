<?php

namespace App\Http\Controllers;

use App\Services\Enums\MessagesEnum;
use App\Services\Orders\SubscriptionService;
use Exception;

class SubscriptionController extends Controller
{
    public function index(SubscriptionService $subscription_service)
    {
        try {
            $subscriptions = $subscription_service->allOrdered();

            return $this->successResponse(
                MessagesEnum::SUBSCRIPTIONS_FETCHED_SUCCESS,
                $subscriptions
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
}
