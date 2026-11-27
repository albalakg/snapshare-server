<?php

namespace App\Mail\V2;

class AddonReceiptMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'התוספת הופעלה: ' . $this->d('short_title');
    }

    protected function viewName(): string
    {
        return 'addonReceipt';
    }
}
