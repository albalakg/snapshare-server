<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Enums\LogsEnum;
use App\Services\Enums\MailEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Events\RetentionService;
use App\Services\Events\EventService;
use App\Services\Helpers\LogService;
use App\Services\Helpers\MailService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

class DisableEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event:disable';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable finished events whose retention ended (Classic 180 / Premium 365 days)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $event_service = new EventService;
        $mail_service = new MailService;

        $events = Event::join('users', 'users.id', 'events.user_id')
            ->join('orders', 'orders.id', 'events.order_id')
            ->where('events.status', StatusEnum::ACTIVE)
            ->select('events.id', 'events.name', 'events.finished_at', 'users.first_name', 'users.email', 'events.status', 'orders.subscription_id')
            ->get();

        foreach ($events as $event) {
            try {
                // Retention (t142u): Classic 180 / Premium 365 days (subscriptions.storage_time), same rule as event:warning.
                $should_disable = !RetentionService::isProtected((int) $event->id)
                    && RetentionService::shouldDisable($event->finished_at, (int) $event->subscription_id);

                if (! $should_disable) {
                    LogService::init()->info(LogsEnum::EVENT_DISABLED.' SKIP', ['id' => $event->id]);

                    continue;
                }

                $event_service->disable($event->id);
                $data = [
                    'event' => $event,
                    'first_name' => $event->first_name ?? '',
                ];
                $mail_service->send($event->email, MailEnum::EVENT_DISABLED, $data, ['dedupe' => 'event:'.$event->id.':disabled', 'event_id' => $event->id]);
                LogService::init()->info(LogsEnum::EVENT_DISABLED, ['id' => $event->id]);

            } catch (Exception $ex) {
                LogService::init()->error($ex, ['id' => $event->id, 'method' => LogsEnum::EVENT_DISABLED]);
            }
        }
    }
}
