<?php

namespace App\Console\Commands;

use Exception;
use App\Services\Events\RetentionService;
use Carbon\Carbon;
use App\Models\Event;
use Illuminate\Console\Command;
use App\Services\Enums\LogsEnum;
use App\Services\Enums\MailEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Helpers\LogService;
use App\Services\Events\EventService;
use App\Services\Helpers\MailService;
use App\Services\Icebreaker\IcebreakerConfigService;

class EndEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event:end';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for events that need to end and update their status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $mail_service = new MailService();
        $event_service = new EventService();
        $icebreaker_service = new IcebreakerConfigService();
        $events = Event::join('users', 'users.id', 'events.user_id')
            ->leftJoin('orders', 'orders.id', 'events.order_id')
            ->where('events.finished_at', '<=', Carbon::now())
            ->where('events.status', StatusEnum::IN_PROGRESS)
            ->withCount('assets')
            ->select('events.id', 'events.name', 'events.starts_at', 'events.finished_at', 'users.first_name', 'users.email', 'events.status', 'orders.subscription_id')
            ->get();
            

        LogService::init()->info(LogsEnum::EVENT_ENDED . " EVENTS", ['events' => $events->toArray()]);
        
        foreach ($events as $event) {
            try {
                LogService::init()->info(LogsEnum::EVENT_ENDED . "START", ['id' => $event->id]);
                $event_service->updateStatus(StatusEnum::ACTIVE, $event->id);
                $icebreaker_service->cooldownOnEventEnd($event->id);
                $data = [
                    'event' => $event,
                    'first_name' => $event->first_name ?? '',
                    'date' => $event->starts_at,
                    'assets_count' => $event->assets_count ?? 0,
                    'event_url' => config('app.client_url') . '/event/assets',
                    'retention_until' => RetentionService::until($event->finished_at, $event->subscription_id ? (int) $event->subscription_id : null),
                ];
                $mail_service->send($event->email, MailEnum::EVENT_FINISHED, $data, ['dedupe' => 'event:' . $event->id . ':finished', 'event_id' => $event->id]);
                LogService::init()->info(LogsEnum::EVENT_ENDED, ['id' => $event->id]);
            } catch(Exception $ex) {
                LogService::init()->error($ex, ['id' => $event->id, 'method' => LogsEnum::EVENT_ENDED]);
            }
        }
    }
}
