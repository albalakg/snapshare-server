<?php

namespace App\Mail\V2;

class AdminAddonPurchaseMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'תוספת נרכשה: ' . $this->d('title') . ' · ₪' . number_format((float) $this->d('amount', 0), 2);
    }

    protected function viewName(): string
    {
        return 'adminAddonPurchase';
    }
}
