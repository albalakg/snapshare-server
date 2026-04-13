<?php

namespace Database\Seeders;

use App\Services\Enums\StatusEnum;
use App\Services\Enums\SubscriptionEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Card-aligned plans. storage_time is always in hours (trial: 1h; classic: ~6 months; premium: ~12 months).
     */
    public function run(): void
    {
        $now = now();

        $plans = [
            [
                'id' => SubscriptionEnum::TRIAL_ID,
                'name' => SubscriptionEnum::TRIAL,
                'status' => StatusEnum::ACTIVE,
                'price' => 0,
                'events_allowed' => 1,
                'files_allowed' => 10,
                'storage_time' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => SubscriptionEnum::CLASSIC_ID,
                'name' => SubscriptionEnum::CLASSIC,
                'status' => StatusEnum::ACTIVE,
                'price' => 200,
                'events_allowed' => 1,
                'files_allowed' => 1000,
                'storage_time' => 180 * 24,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => SubscriptionEnum::PREMIUM_ID,
                'name' => SubscriptionEnum::PREMIUM,
                'status' => StatusEnum::ACTIVE,
                'price' => 300,
                'events_allowed' => 1,
                'files_allowed' => 5000,
                'storage_time' => 365 * 24,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('subscriptions')->upsert(
            $plans,
            ['id'],
            ['name', 'status', 'price', 'events_allowed', 'files_allowed', 'storage_time', 'updated_at']
        );
    }
}
