<?php

namespace App\Services\Orders;

use Exception;
use App\Models\User;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\Helpers\LogService;
use App\Services\Orders\Providers\PayPlusProvider;
use App\Services\Orders\Providers\IPaymentProvider;

class PaymentService
{
    const PROVIDERS = [
        'visa' => PayPlusProvider::class
    ];

    const PAYMENT_METHODS = [
        'visa'
    ];

    private LogService $log_service;

    private IPaymentProvider $payment_provider;

    /**
     * @param string $provider
     * @return void
    */
    public function __construct(string $provider = 'visa')
    {
        $this->log_service  = new LogService('payment');
        $this->setProvider($provider);
    }

    /**
     * @param Order $order
     * @param User $user
     * @param Subscription $subscription
     * @return array;
    */
    public function startTransaction(Order $order, User $user, Subscription $subscription): array
    {
        $this->log_service->info('Starting the order\'s process', ['order_id' => $order->id]);
        $this->payment_provider->buildPayment($order, $user, $subscription)
            ->startTransaction();
        if (!$this->payment_provider->isTransactionValid()) {
            throw new Exception('The transaction failed in the order process');
        }

        $this->log_service->info('Finished the order\'s process successfully', ['order_id' => $order->id]);
        return [
            'token'         => $this->payment_provider->getGeneratedPageToken(),
            'link'          => $this->payment_provider->getGeneratedPageLink(),
            'supplier_id'   => $this->payment_provider->getProviderID()
        ];
    }
        
    /**
     * @param Order $order
     * @return void
    */ 
    public function sendInvoice(Order $order, User $user, Subscription $subscription)
    {
        $this->payment_provider->sendInvoice($order, $user, $subscription);
        if (!$this->payment_provider->isInvoiceValid()) {
            throw new Exception('The invoice failed in the order process');
        }
    }
    
    /**
     * @param array $response
     * @return bool
    */
    public function isPaymentCallbackValid(array $response): bool
    {
        try {
            return $this->payment_provider->isPaymentCallbackValid($response);
        } catch(Exception $ex) {
            $this->log_service->critical($ex);
            return false;
        }
    }

    /**
     * find the provider and set it
     *
     * @param string $provider
     * @return void
    */
    public function setProvider(string $provider)
    {
        try {
            $provider_class = self::PROVIDERS[$provider];
            $this->payment_provider = new $provider_class;
        } catch (Exception $ex) {
            $this->log_service->error($ex);
            throw new Exception('Failed to set provider with: ' . $provider);
        }
    }
}
