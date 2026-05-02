<?php

namespace App\Services\Orders;

use App\Services\Helpers\LogService;
use Exception;
use App\Models\Event;
use App\Models\User;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\Enums\LogsEnum;
use App\Services\Enums\MailEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Users\UserService;
use App\Services\Enums\MessagesEnum;
use App\Services\Events\EventService;
use App\Services\Helpers\MailService;
use App\Services\Orders\SubscriptionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

use function Aws\boolean_value;

class StoreService
{
    private LogService $log_service;

    public function __construct(
        private ?PaymentService $payment_service = null,
        private ?MailService $mail_service = null,
        private ?SubscriptionService $subscription_service = null,
        private ?UserService $user_service = null,
        private ?EventService $event_service = null,
    ) {
        $this->log_service  = new LogService('payment');
    }

    /**
     * @param int $order_id
     * @return ?Order
     */
    public function find(int $order_id): ?Order
    {
        return Order::with('subscription')->find($order_id);
    }

    /**
     * @param string $pageUid
     * @return ?Order
     */
    public function findByPageUid(string $pageUid): ?Order
    {
        return Order::with('subscription')->where('token', $pageUid)->first();
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
     * @return boolean
     */
    public function createDemo(array $data, int $user_id): bool
    {
        if (!$subscription = $this->subscription_service->findByName($data['subscription'])) {
            throw new Exception(MessagesEnum::SUBSCRIPTION_NOT_FOUND);
        }

        if ($this->hasOrderInProgress($user_id)) {
            throw new Exception(MessagesEnum::ORDER_ALREADY_IN_PROGRESS);
        }


        $user = $this->user_service->find($user_id);
        if (!$user) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }

        try {
            $new_order = $this->addNewOrder($subscription, $user_id, StatusEnum::ACTIVE);
            try {
                $this->event_service->createDemo($new_order, $user);
                return true;
            } catch (Exception $ex) {
                $new_order->update([
                    'status' => StatusEnum::INACTIVE,
                ]);
                LogService::init()->critical($ex, ['order_id' => $new_order->id, 'method' => LogsEnum::FAILED_TO_CREATE_DEMO_EVENT]);
            }
        } catch (Exception $e) {
            LogService::init()->critical($ex, ['user_id' => $user_id, 'method' => LogsEnum::FAILED_TO_CREATE_DEMO_EVENT]);
        }

        return false;
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

        $order_price = $this->calculateOrderPrice($subscription, $user_id);

        if ($this->hasOrderInProgress($user_id)) {
            $order = Order::where('user_id', $user_id)
                ->where('status', StatusEnum::IN_PROGRESS)
                ->where('subscription_id', $subscription->id)
                ->first();

            if (
                $order
                && $this->isSamePrice((float) $order->price, $order_price)
                && $order->created_at >= now()->subHour()
            ) {
                return [
                    'payment_page_link' => $order->payment_page_link,
                ];
            }

            Order::where('user_id', $user_id)
                ->where('status', StatusEnum::IN_PROGRESS)
                ->update([
                    'status' => StatusEnum::INACTIVE,
                ]);
        }

        $user = $this->user_service->find($user_id);
        if (!$user) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }


        try {
            $new_order = $this->addNewOrder(
                $subscription,
                $user_id,
                StatusEnum::PENDING,
                $order_price
            );
            $transaction_response = $this->payment_service->startTransaction($new_order, $user, $subscription);
            $new_order->update([
                'token'             => $transaction_response['token'],
                'supplier_id'       => $transaction_response['supplier_id'],
                'status'            => StatusEnum::IN_PROGRESS,
                'payment_page_link' => $transaction_response['link'],
            ]);

            $this->sendInvoice($new_order, $user, $subscription);

            return [
                'payment_page_link' => $new_order->payment_page_link,
            ];
        } catch (Exception $e) {
            $new_order->update([
                'status' => StatusEnum::INACTIVE,
            ]);

            // TODO:: add critical log

            return null;
        }
    }

    public function cancelOrder(string $pageRequestUid)
    {
        try {
            $order = $this->findByPageUid($pageRequestUid);
            if (!$order) {
                throw new Exception(MessagesEnum::ORDER_NOT_FOUND);
            }

            $order->update(['status' => StatusEnum::INACTIVE]);
            $this->log_service->info('Order cancelled', ['order_id' => $order->id]);
            return true;
        } catch (Exception $ex) {
            $this->log_service->error('Failed to cancel order', ['error' => $ex->getMessage()]);
            return false;
        }
    }

    /**
     * @param array $data
     * @return void
     */
    public function orderConfirmed(array $data)
    {
        $this->log_service->info('Order callback: step 1 — payload received', ['data' => $data]);

        $pageRequestUid = $data['page_request_uid'] ?? null;
        if (!is_string($pageRequestUid) || $pageRequestUid === '') {
            $this->log_service->info('Order callback: step 2 — abort, page_request_uid missing or invalid', [
                'page_request_uid' => $pageRequestUid,
            ]);
            throw new Exception(MessagesEnum::ORDER_CALLBACK_PAYLOAD_INVALID);
        }
        $this->log_service->info('Order callback: step 2 — page_request_uid OK', ['page_request_uid' => $pageRequestUid]);

        if (!$order = $this->findByPageUid($pageRequestUid)) {
            $this->log_service->info('Order callback: step 3 — abort, order not found for token', [
                'page_request_uid' => $pageRequestUid,
            ]);
            throw new Exception(MessagesEnum::ORDER_NOT_FOUND);
        }
        $this->log_service->info('Order callback: step 3 — order loaded', [
            'order_id' => $order->id,
            'status' => $order->status,
            'user_id' => $order->user_id,
        ]);

        if ($order->status !== StatusEnum::IN_PROGRESS) {
            $this->log_service->info('Order callback: step 4 — abort, order not IN_PROGRESS', [
                'order_id' => $order->id,
                'status' => $order->status,
            ]);
            throw new Exception(MessagesEnum::ORDER_IN_INVALID_STATUS_WHILE_SETTING_TO_IN_PROGRESS);
        }
        $this->log_service->info('Order callback: step 4 — order status OK (IN_PROGRESS)', ['order_id' => $order->id]);

        if (!$user = $this->user_service->find($order->user_id)) {
            $this->log_service->info('Order callback: step 5 — abort, user not found', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
            ]);
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }
        $this->log_service->info('Order callback: step 5 — user loaded', [
            'order_id' => $order->id,
            'user_id' => $user->id,
        ]);

        if (!$this->payment_service->isPaymentCallbackValid($data)) {
            $this->log_service->info('Order callback: step 6 — payment signature/payload validation failed', [
                'order_id' => $order->id,
            ]);
            $this->updateStatus(StatusEnum::INACTIVE, $order->id);
            $this->log_service->info('Order callback: step 6a — order set to INACTIVE', ['order_id' => $order->id]);
            $this->mail_service->send($user->email, MailEnum::ORDER_FAILED, [
                'order' => $order,
                'first_name' => $user->first_name,
                'failure_reason' => 'ההזמנה נכשלה מסיבה לא ידועה, אנו מציעים לנסות שוב',
                'retry_url' => config('app.client_url') . '/order',
            ]);
            $this->log_service->info('Order callback: step 6b — failure mail dispatched', [
                'order_id' => $order->id,
            ]);
            throw new Exception(MessagesEnum::ORDER_CALLBACK_PAYLOAD_INVALID);
        }
        $this->log_service->info('Order callback: step 6 — payment validation OK', ['order_id' => $order->id]);

        DB::transaction(function () use ($order, $pageRequestUid) {
            $this->log_service->info('Order callback: step 7 — DB transaction started', [
                'order_id' => $order->id,
                'page_request_uid' => $pageRequestUid,
            ]);

            $this->syncConfirmedOrderEvents($order);

            $this->log_service->info('Order callback: step 8 — events synced for confirmed order', ['order_id' => $order->id]);

            $this->updateOrderConfirmed($order->id);

            $this->log_service->info('Order callback: step 9 — order updated to ACTIVE with paid_at', ['order_id' => $order->id]);
        });

        $this->log_service->info('Order callback: step 10 — DB transaction finished', ['order_id' => $order->id]);

        $this->mail_service->send($user->email, MailEnum::ORDER_CONFIRMED, [
            'order' => $order,
            'first_name' => $user->first_name,
            'event_url' => config('app.client_url') . '/event',
        ]);
        $this->log_service->info('Order callback: step 11 — confirmation mail dispatched', [
            'order_id' => $order->id,
        ]);
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

    public function updateOrderConfirmed(int $order_id): bool
    {
        return Order::where('id', $order_id)->update([
            'status' => StatusEnum::ACTIVE,
            'paid_at' => now(),
        ]);
    }

    private function isSamePrice(float $first_price, float $second_price): bool
    {
        return abs($first_price - $second_price) < 0.01;
    }

    /**
     * Events store order_id only; subscription tier is on orders (subscription_id).
     * On upgrade, events are reassigned to the new order so limits and SQL joins on orders.subscription_id use the upgraded plan.
     */
    private function syncConfirmedOrderEvents(Order $order): void
    {
        $this->log_service->info('Order callback: sync events — start', [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
        ]);

        $current_order = $this->getCurrentActiveOrder($order->user_id);

        if ($this->isSubscriptionUpgrade($order, $current_order)) {
            $this->log_service->info('Order callback: sync events — upgrade path', [
                'order_id' => $order->id,
                'previous_active_order_id' => $current_order->id,
            ]);

            Event::where('user_id', $order->user_id)
                ->where('order_id', $current_order->id)
                ->update([
                    'order_id' => $order->id,
                ]);

            $this->updateStatus(StatusEnum::INACTIVE, $current_order->id);

            $allowed = (int) ($order->subscription->events_allowed ?? 1);
            $existing = (int) Event::where('order_id', $order->id)->count();
            $this->log_service->info('Order callback: sync events — upgrade, creating slot events', [
                'order_id' => $order->id,
                'events_allowed' => $allowed,
                'existing_on_order' => $existing,
            ]);
            for ($i = $existing; $i < $allowed; $i++) {
                $this->event_service->create($order);
            }

            $this->log_service->info('Order callback: sync events — upgrade path done', ['order_id' => $order->id]);
            return;
        }

        $eventsCount = (int) ($order->subscription->events_allowed ?? 1);
        $this->log_service->info('Order callback: sync events — new order path', [
            'order_id' => $order->id,
            'events_to_create' => $eventsCount,
        ]);
        for ($i = 0; $i < $eventsCount; $i++) {
            $this->event_service->create($order);
        }
        $this->log_service->info('Order callback: sync events — new order path done', ['order_id' => $order->id]);
    }

    private function isSubscriptionUpgrade(Order $order, ?Order $current_order = null): bool
    {
        if (!$current_order || $current_order->id === $order->id) {
            return false;
        }

        if (!$current_order->subscription || !$order->subscription) {
            return false;
        }

        return (float) $order->subscription->price > (float) $current_order->subscription->price;
    }

    private function calculateOrderPrice(Subscription $subscription, int $user_id): float
    {
        $new_subscription_price = (float) $subscription->price;
        $current_order = $this->getCurrentActiveOrder($user_id);

        if (!$current_order || !$current_order->subscription) {
            return $new_subscription_price;
        }

        $current_subscription_price = (float) $current_order->subscription->price;

        if ($new_subscription_price <= $current_subscription_price) {
            return $new_subscription_price;
        }

        return round($new_subscription_price - $current_subscription_price, 2);
    }

    private function getCurrentActiveOrder(int $user_id): ?Order
    {
        return Order::with('subscription')
            ->where('user_id', $user_id)
            ->where('status', StatusEnum::ACTIVE)
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->first();
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

    /**
     * @param Subscription $subscription
     * @param int $user_id
     * @param int $status
     * @return Order
     */
    private function addNewOrder(Subscription $subscription, int $user_id, int $status = StatusEnum::PENDING, ?float $price = null): Order
    {
        $new_order = new Order();
        $new_order->user_id = $user_id;
        $new_order->subscription_id = $subscription->id;
        $new_order->price = $price ?? $subscription->price;
        $new_order->status = $status;
        $new_order->order_number = $this->generateOrderNumber();
        $new_order->save();

        return $new_order;
    }
}
