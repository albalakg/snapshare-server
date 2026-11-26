<?php

namespace App\Console\Commands;

use App\Services\Messaging\Providers\ProviderFactory;
use App\Services\Messaging\TemplateService;
use Illuminate\Console\Command;

class WhatsAppSubmitSystemTemplates extends Command
{
    protected $signature = 'whatsapp-messaging:submit-system-templates';

    protected $description = 'Submit the SnapShare ready templates to Meta for review (one-time, after WABA setup)';

    public function handle(): int
    {
        if (ProviderFactory::isMock()) {
            $this->warn('Driver is mock — nothing is sent to Meta. Set WHATSAPP_* credentials first.');
        }
        foreach ((new TemplateService())->submitSystemTemplates() as $key => $status) {
            $this->line("{$key}: {$status}");
        }

        return self::SUCCESS;
    }
}
