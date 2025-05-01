<?php

namespace Database\Seeders;

use App\Models\Subscription;
use App\Services\Enums\SubscriptionEnum;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
    */
    public function run(): void
    {
        $roles = collect(SubscriptionEnum::getAll())->map(function ($role_id, $role) {
            return [
                'id' => $role_id,
                'name' => $role
            ];
        })->toArray();
        
        Subscription::insert($roles);
    }
}
