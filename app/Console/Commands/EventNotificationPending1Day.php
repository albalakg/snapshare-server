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

class EventNotificationPending1Day extends Command
{
    protected $signature = 'event:notification-pending-1-day';
    protected $description = 'Warn users 1 day before their event starts if it is still pending';

    public function handle()
    {
        $mail_service = new MailService();
        $now = Carbon::now();

        $windowStart = $now->copy()->addDays(1)->startOfDay();
        $windowEnd   = $now->copy()->addDays(1)->endOfDay();

        $events = Event::join('users', 'users.id', '=', 'events.user_id')
            ->where('events.status', StatusEnum::PENDING)
            ->whereNotNull('events.name')
            ->where('events.name', '!=', '')
            ->whereNotNull('events.starts_at')
            ->whereBetween('events.starts_at', [$windowStart, $windowEnd])
            ->select(
                'events.id',
                'events.name',
                'events.starts_at',
                'users.first_name',
                'users.email'
            )
            ->get();

        $event_url = config('app.client_url') . '/event';

        foreach ($events as $event) {
            try {
                $data = [
                    'event' => $event,
                    'first_name' => $event->first_name,
                    'event_url' => $event_url,
                ];

                $mail_service->send($event->email, MailEnum::EVENT_PENDING_STARTS_IN_1_DAY, $data);

                LogService::init()->info(LogsEnum::EVENT_PENDING_STARTS_IN_1_DAY, [
                    'id' => $event->id
                ]);
            } catch (Exception $ex) {
                LogService::init()->error($ex, [
                    'id' => $event->id,
                    'method' => LogsEnum::EVENT_PENDING_STARTS_IN_1_DAY
                ]);
            }
        }
    }
}
