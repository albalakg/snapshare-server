<?php

namespace App\Mail\V2;

class WhatsAppReadyMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'מרכז ההודעות שלכם מוכן';
    }

    protected function viewName(): string
    {
        return 'whatsAppReady';
    }
}
