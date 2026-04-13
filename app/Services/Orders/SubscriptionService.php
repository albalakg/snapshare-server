<?php

namespace App\Services\Orders;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionService
{
    public function __construct(
    ) {}

    public function find(int $id): ?Subscription
    {
        return Subscription::find($id);
    }

    public function create(string $name): ?Subscription
    {
        return Subscription::where('name', $name)->first();
    }

    public function findByName(string $name): ?Subscription
    {
        return Subscription::where('name', $name)->first();
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function allOrdered(): Collection
    {
        return Subscription::query()->orderBy('id')->get();
    }
}
