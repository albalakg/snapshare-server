<?php

namespace App\Console\Commands;

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Console\Command;

class SeedSubscriptionPlansCommand extends Command
{
    protected $signature = 'subscriptions:seed';

    protected $description = 'Upsert subscription plans (trial, classic, premium) from product definitions';

    public function handle(): int
    {
        $this->info('Seeding subscription plans…');
        $this->call(SubscriptionPlanSeeder::class);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
