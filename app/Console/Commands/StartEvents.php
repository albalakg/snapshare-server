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

class StartEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for events that need to start and update their status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $mail_service   = new MailService();
        $event_service  = new EventService();

        $events = Event::join('users', 'users.id', 'events.user_id')
                ->where('events.starts_at', '<=', Carbon::now()->addHours(8))
                ->where('events.status', StatusEnum::READY)
                ->select(
                    'events.id',
                    'events.name',
                    'events.finished_at',
                    'users.first_name',
                    'users.email',
                    'events.status'
                )
                ->get();
                
        foreach ($events as $event) {
           try {
                $event_service->updateStatus(StatusEnum::IN_PROGRESS, $event->id);
                $data = [
                    'event' => $event,
                    'first_name' => $event->first_name ?? '',
                    'event_url' => config('app.client_url') . '/event',
                ];
                $mail_service->send($event->email, MailEnum::EVENT_STARTED, $data);
                LogService::init()->info(LogsEnum::EVENT_STARTED, ['id' => $event->id]);
            } catch(Exception $ex) {
                LogService::init()->error($ex, ['id' => $event->id, 'method' => LogsEnum::EVENT_STARTED]);
            }
        }
    }
}
