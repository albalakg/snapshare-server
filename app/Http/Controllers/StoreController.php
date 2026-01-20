<?php

namespace App\Http\Controllers;

use App\Services\Events\EventService;
use Exception;
use App\Services\Users\UserService;
use App\Services\Enums\MessagesEnum;
use Illuminate\Support\Facades\Auth;
use App\Services\Helpers\MailService;
use App\Services\Orders\StoreService;
use App\Services\Orders\PaymentService;
use Illuminate\Support\Facades\Request;
use App\Http\Requests\CreateOrderRequest;
use App\Services\Orders\SubscriptionService;

class StoreController extends Controller
{
    public function get()
    {
        try {
            $order_service = new StoreService();
            $response = $order_service->get();
            return $this->successResponse(MessagesEnum::ORDER_CREATED_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function createDemo(CreateOrderRequest $request)
    {
        try {
            $order_service = new StoreService(
                new PaymentService,
                null,
                new SubscriptionService,
                new UserService,
                new EventService
            );
            $response = $order_service->createDemo($request->validated(), Auth::user()->id);
            return $this->successResponse(MessagesEnum::ORDER_CREATED_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function createOrder(CreateOrderRequest $request)
    {
        try {
            $order_service = new StoreService(
                new PaymentService,
                null,
                new SubscriptionService,
                new UserService
            );
            $response = $order_service->createOrder($request->validated(), Auth::user()->id);
            return $this->successResponse(MessagesEnum::ORDER_CREATED_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function orderCallback(Request $request)
    {
        try {
            $order_service = new StoreService(
                new PaymentService,
                new MailService,
                new SubscriptionService,
                new UserService
            );

            $transition = $request->input('transaction');
            $data = [
                'page_request_uid'  => $transition['payment_page_request_uid']  ?? null,
                'approval_number'   => $transition['approval_number']           ?? null,
                'browser'           => $request->header('user-agent'),
                'hash'              => $request->header('hash'),
            ];
            
            $response = $order_service->orderConfirmed($data);
            return $this->successResponse('Order\'s status updated successfully to completed', $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
}
