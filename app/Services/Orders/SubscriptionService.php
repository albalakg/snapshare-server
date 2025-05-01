<?php

namespace App\Services\Orders;

use App\Models\Subscription;

class SubscriptionService
{
    public function __construct(
    ) {}
    
    /**
     * @param int $id
     * @return ?Subscription
    */
    public function find(int $id): ?Subscription
    {
        return Subscription::find($id);
    }
    
    /**
     * @param string $name
     * @return ?Subscription
    */
    public function create(string $name): ?Subscription
    {
        return Subscription::where('name', $name)->first();
    }
    
    /**
     * @param string $name
     * @return ?Subscription
    */
    public function findByName(string $name): ?Subscription
    {
        return Subscription::where('name', $name)->first();
    }
}
