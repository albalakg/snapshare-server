<?php

namespace App\Console\Commands;

use App\Services\Messaging\Dispatcher;
use Illuminate\Console\Command;

class WhatsAppMessagingTick extends Command
{
    protected $signature = 'whatsapp-messaging:tick';

    protected $description = 'WhatsApp guest messaging: sync template reviews, materialize automations, dispatch due sends';

    public function handle(): int
    {
        $out = (new Dispatcher())->tick();
        $this->line(json_encode($out));

        return self::SUCCESS;
    }
}
