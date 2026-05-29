<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppCampaignService;
use Illuminate\Console\Command;

class DispatchScheduledWhatsAppCampaigns extends Command
{
    protected $signature = 'whatsapp:dispatch-scheduled';

    protected $description = 'Dispatch WhatsApp campaigns that are scheduled and due to send';

    public function handle(WhatsAppCampaignService $campaignService): int
    {
        $dispatched = $campaignService->dispatchDueScheduledCampaigns();

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} scheduled WhatsApp campaign(s).");
        }

        return self::SUCCESS;
    }
}
