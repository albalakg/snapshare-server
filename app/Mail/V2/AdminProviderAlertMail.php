<?php

namespace App\Mail\V2;

class AdminProviderAlertMail extends SnapMail
{
    public function mailSubject(): string
    {
        return '[ספק] ' . $this->d('provider') . ' מחזיר שגיאות';
    }

    protected function viewName(): string
    {
        return 'adminProviderAlert';
    }
}
