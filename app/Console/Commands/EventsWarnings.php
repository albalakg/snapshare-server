<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Enums\LogsEnum;
use App\Services\Enums\MailEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Events\RetentionService;
use App\Services\Helpers\LogService;
use App\Services\Helpers\MailService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

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
        $mail_service = new MailService;

        $events = Event::join('users', 'users.id', 'events.user_id')
            ->join('orders', 'orders.id', 'events.order_id')
            ->where('events.status', StatusEnum::ACTIVE)
            ->select('events.id', 'events.name', 'events.finished_at', 'users.first_name', 'users.email', 'events.status', 'orders.subscription_id')
            ->get();

        LogService::init()->info(LogsEnum::EVENT_WARNED, ['events' => $events->toArray()]);

        foreach ($events as $event) {
            try {
                // Retention (t142u): warn N days before RetentionService::until() — Classic 180 / Premium 365 days.
                $should_warn = RetentionService::shouldWarn($event->finished_at, (int) $event->subscription_id);
                LogService::init()->info(LogsEnum::EVENT_WARNED.' DIFF', ['diff' => RetentionService::daysSince($event->finished_at)]);

                if (! $should_warn) {
                    LogService::init()->info(LogsEnum::EVENT_WARNED.' SKIP', ['id' => $event->id]);

                    continue;
                }

                $data = [
                    'event' => $event,
                    'first_name' => $event->first_name ?? '',
                    'download_url' => config('app.client_url').'/event/assets',
                    'deactivation_date' => RetentionService::until($event->finished_at, (int) $event->subscription_id),
                    'days_remaining' => (int) config('emails.triggers.deactivation_warning_days', 3),
                ];
                $mail_service->send($event->email, MailEnum::EVENT_WARNING_BEFORE_DEACTIVATION, $data, ['dedupe' => 'event:'.$event->id.':retention-warning', 'event_id' => $event->id]);
                LogService::init()->info(LogsEnum::EVENT_WARNED.' SUCCESS', ['id' => $event->id]);
            } catch (Exception $ex) {
                LogService::init()->error($ex, ['id' => $event->id, 'method' => LogsEnum::EVENT_WARNED]);
            }
        }
    }
}
