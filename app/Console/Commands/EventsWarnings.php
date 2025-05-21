<?php

namespace App\Console\Commands;

use Exception;
use Carbon\Carbon;
use App\Models\Event;
use Illuminate\Console\Command;
use App\Services\Enums\LogsEnum;
use App\Services\Enums\MailEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Helpers\LogService;
use App\Services\Helpers\MailService;
use App\Services\Enums\SubscriptionEnum;

class EventsWarnings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event:warning';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warn users when their event is close to being disabled based on subscription type';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $mail_service = new MailService();

        $events = Event::join('users', 'users.id', 'events.user_id')
            ->join('orders', 'orders.id', 'events.order_id')
            ->where('events.status', StatusEnum::ACTIVE)
            ->select('events.id', 'events.name', 'events.finished_at', 'users.first_name', 'users.email', 'events.status', 'orders.subscription_id')
            ->get();

        LogService::init()->info(LogsEnum::EVENT_WARNED, ['events' => $events]);

        foreach ($events as $event) {
            try {
                $should_warn = false;
                $finishedAt = Carbon::parse($event->finished_at);
                $days_diff = $finishedAt->diffInDays(Carbon::now()->endOfDay());
                LogService::init()->info(LogsEnum::EVENT_WARNED . " DIFF", ['diff' => $days_diff]);
                if (
                    ($event->subscription_id === SubscriptionEnum::NORMAL_ID &&
                    $days_diff === 11) ||
                    ($event->subscription_id === SubscriptionEnum::PREMIUM_ID &&
                    $days_diff === 27)
                ) {
                    $should_warn = true;
                } 

                if (!$should_warn) {
                    LogService::init()->info(LogsEnum::EVENT_WARNED . " SKIP", ['events' => $events]);
                    continue;
                }
                
                $data = [
                    'event' => $event,
                    'first_name' => $event->first_name ?? '',
                    'download_url' => config('app.client_url') . "/events/assets",
                    'deactivation_date' => Carbon::now()->addDays(3),
                    'days_remaining' => 3,
                ];
                $mail_service->send($event->email, MailEnum::EVENT_WARNING_BEFORE_DEACTIVATION, $data);
                LogService::init()->info(LogsEnum::EVENT_WARNED . " SUCCESS", ['id' => $event->id]);
            } catch(Exception $ex) {
                LogService::init()->error($ex, ['id' => $event->id, 'method' => LogsEnum::EVENT_WARNED]);
            }
        }
    }
}
