<?php

namespace App\Mail\V2;

class FreeOrderConfirmedMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'האירוע שלכם נוצר — בלי תשלום';
    }

    protected function viewName(): string
    {
        return 'freeOrderConfirmed';
    }
}
