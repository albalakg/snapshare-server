<?php

namespace App\Mail\V2;

class AddonFailedMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'התשלום על התוספת לא הושלם';
    }

    protected function viewName(): string
    {
        return 'addonFailed';
    }
}
