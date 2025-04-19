<?php

namespace App\Services\Orders;

use Exception;
use App\Models\User;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\Enums\MailEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Users\UserService;
use App\Services\Enums\MessagesEnum;
use App\Services\Events\EventService;
use App\Services\Helpers\MailService;
use App\Services\Orders\SubscriptionService;
use Illuminate\Database\Eloquent\Collection;

class StoreService
{
    public function __construct(
        private ?PaymentService $payment_service = null,
        private ?MailService $mail_service = null,
        private ?SubscriptionService $subscription_service = null,
        private ?UserService $user_service = null,
        private ?EventService $event_service = null,
    ) {}

    /**
     * @param int $order_id
     * @return Order
     */
    public function find(int $order_id): Order
    {
        return Order::with('subscription')->find($order_id);
    }

    /**
     * @param string $token
     * @return Order
     */
    public function findByToken(string $token): Order
    {
        return Order::where('token', $token)->first();
    }

    /**
     * @return Collection
     */
    public function get(): Collection
    {
        return Order::get();
    }

    /**
     * @param int $user_id 
     * @return bool
     */
    public function hasOrderInProgress(int $user_id): bool
    {
        return Order::where('user_id', $user_id)
            ->where('status', StatusEnum::IN_PROGRESS)
            ->count();
    }

    /**
     * @param array $data
     * @param int $user_id
     * @return ?array
     */
    public function createOrder(array $data, int $user_id): ?array
    {
        if (!$subscription = $this->subscription_service->findByName($data['subscription'])) {
            throw new Exception(MessagesEnum::SUBSCRIPTION_NOT_FOUND);
        }

        if ($this->hasOrderInProgress($user_id)) {
            throw new Exception(MessagesEnum::ORDER_ALREADY_IN_PROGRESS);
        }

        $new_order = new Order();
        $new_order->user_id = $user_id;
        $new_order->subscription_id = $subscription->id;
        $new_order->price = $subscription->price;
        $new_order->status = StatusEnum::PENDING;
        $new_order->order_number = $this->generateOrderNumber();
        $new_order->save();

        $user = $this->user_service->find($user_id);

        try {
            $transaction_response = $this->payment_service->startTransaction($new_order, $user, $subscription);
            $new_order->update([
                'token'       => $transaction_response['token'],
                'supplier_id' => $transaction_response['supplier_id'],
                'status' => StatusEnum::IN_PROGRESS,
            ]);

            $this->sendInvoice($new_order, $user, $subscription);

            return [
                'payment_page_link' => $transaction_response['link']
            ];
        } catch (Exception $e) {
            $new_order->update([
                'status' => StatusEnum::INACTIVE,
            ]);

            // TODO:: add critical log

            return null;
        }
    }

    /**
     * @param array $data
     * @return void
     */
    public function orderConfirmed(array $data)
    {
        if (!$order = $this->findByToken($data['page_request_uid'])) {
            throw new Exception(MessagesEnum::ORDER_NOT_FOUND);
        }

        if ($order->status !== StatusEnum::IN_PROGRESS) {
            throw new Exception(MessagesEnum::ORDER_IN_INVALID_STATUS_WHILE_SETTING_TO_IN_PROGRESS);
        }

        if (!$user = $this->user_service->find($order->user_id)) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }

        if($this->payment_service->isPaymentCallbackValid($data)) {
            $this->updateStatus(StatusEnum::INACTIVE, $order->id);
            $this->mail_service->send($user->email, MailEnum::ORDER_FAILED, [
                'order' => $order,
                'first_name' => $user->first_name,
                'failure_reason' => 'ההזמנה נכשלה מסיבה לא ידועה, אנו מציעים לנסות שוב',
                'retry_url' => config('app.client_url') . '/order',
            ]);
            throw new Exception(MessagesEnum::ORDER_CALLBACK_PAYLOAD_INVALID);
        }

        $this->updateStatus(StatusEnum::ACTIVE, $order->id);
        $this->mail_service->send($user->email, MailEnum::ORDER_CONFIRMED, [
            'order' => $order,
            'first_name' => $user->first_name,
            'event_url' => config('app.client_url') . '/event',
        ]);

        foreach ($order->subscription->events_allowed ?? 1 as $event) {
            $this->event_service->create($order);
        }
    }

    /**
     * @param int $status
     * @param int $order_id
     * @return bool
     */
    public function updateStatus(int $status, int $order_id): bool
    {
        return Order::where('id', $order_id)->update([
            'status' => $status
        ]);
    }

    /**
     * Generating a unique order number
     *
     * @return string
     */
    private function generateOrderNumber(): string
    {
        $order_number = 'ON_' . random_int(00000000, 99999999);
        if (Order::where('order_number', $order_number)->exists()) {
            return $this->generateOrderNumber();
        }
        return $order_number;
    }


    /**
     * @param Order $order
     * @param User $user
     * @param Subscription $course
     * @return void
     */
    private function sendInvoice(Order $order, User $user, Subscription $subscription)
    {
        $this->payment_service = new PaymentService();
        $this->payment_service->sendInvoice($order, $user, $subscription);
    }
}
