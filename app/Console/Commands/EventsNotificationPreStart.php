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
use App\Services\Events\EventService;
use App\Services\Helpers\MailService;

class EventsNotificationPreStart extends Command
{
    protected $signature = 'event:notification-pre-start';
    protected $description = 'Notify users before their event starts (5 days / 8 hours)';

    public function handle()
    {
        $mail_service = new MailService();
        $now = Carbon::now();

        // Time windows
        $fiveDaysStart  = $now->copy()->addDays(5)->startOfHour();
        $fiveDaysEnd    = $now->copy()->addDays(5)->endOfHour();

        $eightHoursStart = $now->copy()->addHours(8)->startOfHour();
        $eightHoursEnd   = $now->copy()->addHours(8)->endOfHour();

        // --- SINGLE QUERY ---
        $events = Event::join('users', 'users.id', '=', 'events.user_id')
            ->where('events.status', StatusEnum::READY)
            ->where(function ($q) use (
                $fiveDaysStart, $fiveDaysEnd, $eightHoursStart, $eightHoursEnd
            ) {
                $q->whereBetween('events.starts_at', [$fiveDaysStart, $fiveDaysEnd])
                  ->orWhereBetween('events.starts_at', [$eightHoursStart, $eightHoursEnd]);
            })
            ->select(
                'events.id',
                'events.name',
                'events.starts_at',
                'users.first_name',
                'users.email'
            )
            ->get();

        // Group the events in memory
        $events5Days = $events->filter(function ($event) use ($fiveDaysStart, $fiveDaysEnd) {
            return Carbon::parse($event->starts_at)->between($fiveDaysStart, $fiveDaysEnd);
        });

        $events8Hours = $events->filter(function ($event) use ($eightHoursStart, $eightHoursEnd) {
            return Carbon::parse($event->starts_at)->between($eightHoursStart, $eightHoursEnd);
        });


        $event_url = config('app.client_url') . '/events';

        // --- NOTIFY FOR 5 DAYS ---
        foreach ($events5Days as $event) {
            try {
                $data = [
                    'event' => $event,
                    'first_name' => $event->first_name,
                    'event_url' => $event_url,
                ];

                $mail_service->send($event->email, MailEnum::EVENT_STARTS_IN_5_DAYS, $data);

                LogService::init()->info(LogsEnum::EVENT_STARTS_IN_5_DAYS, [
                    'id' => $event->id
                ]);
            } catch (Exception $ex) {
                LogService::init()->error($ex, [
                    'id' => $event->id,
                    'method' => LogsEnum::EVENT_STARTS_IN_5_DAYS
                ]);
            }
        }


        // --- NOTIFY FOR 8 HOURS ---
        foreach ($events8Hours as $event) {
            try {
                $data = [
                    'event' => $event,
                    'first_name' => $event->first_name,
                    'event_url' => $event_url,
                ];

                $mail_service->send($event->email, MailEnum::EVENT_STARTS_IN_8_HOURS, $data);

                LogService::init()->info(LogsEnum::EVENT_STARTS_IN_8_HOURS, [
                    'id' => $event->id
                ]);
            } catch (Exception $ex) {
                LogService::init()->error($ex, [
                    'id' => $event->id,
                    'method' => LogsEnum::EVENT_STARTS_IN_8_HOURS
                ]);
            }
        }
    }
}
