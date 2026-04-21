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
use Illuminate\Http\Request;
use App\Http\Requests\CreateOrderRequest;
use App\Services\Helpers\LogService;
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
        $mail_service = new MailService();
        $mail_service->send('gal.blacky@gmail.com', \App\Services\Enums\MailEnum::ORDER_CONFIRMED, [
            'order' => \App\Models\Order::find(42),
            'first_name' => 'גל בדיקה',
            'event_url' => config('app.client_url') . '/event',
        ]);
        return 1;
        
        $log = new LogService('payment');
        $log->info('Order callback received', ['data' => $request->all()]);
        
        try {
            $order_service = new StoreService(
                new PaymentService,
                new MailService,
                new SubscriptionService,
                new UserService,
                new EventService
            );

            // PayPlus sends either a full "Charge" payload (transaction.*) or a minimal signed payload (top-level page_request_uid).
            $data = [
                'page_request_uid' => $request->input('transaction.payment_page_request_uid') ?? null,
                'approval_number'  => data_get($request->all(), 'transaction.approval_number') ?? $request->input('approval_number'),
                'browser'          => $request->input('browser') ?? $request->userAgent(),
                'hash'             => $request->input('hash') ?? $request->header('hash'),
            ];

            $log->info('test data', ['data' => $data]);
            
            $response = $order_service->orderConfirmed($data);
            return $this->successResponse('Order\'s status updated successfully to completed', $response);
        } catch (Exception $ex) {
            $order_service->cancelOrder($request->input('transaction.payment_page_request_uid'));
            return $this->errorResponse($ex);
        }
    }
}
