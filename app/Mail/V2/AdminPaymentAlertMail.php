<?php

namespace App\Mail\V2;

class AdminPaymentAlertMail extends SnapMail
{
    public function mailSubject(): string
    {
        return '[תשלום] callback לא תקין — ' . $this->d('kind') . ' ' . $this->d('id');
    }

    protected function viewName(): string
    {
        return 'adminPaymentAlert';
    }
}
