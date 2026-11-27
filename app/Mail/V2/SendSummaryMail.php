<?php

namespace App\Mail\V2;

class SendSummaryMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'ההודעה נשלחה: ' . (int) $this->d('sent') . ' מתוך ' . (int) $this->d('recipients');
    }

    protected function viewName(): string
    {
        return 'sendSummary';
    }
}
