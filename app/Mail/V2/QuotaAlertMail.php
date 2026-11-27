<?php

namespace App\Mail\V2;

class QuotaAlertMail extends SnapMail
{
    public function mailSubject(): string
    {
        return ((int) $this->d('threshold', 80) >= 100) ? 'מכסת ההודעות לאירוע נגמרה' : 'נותרו לכם ' . (100 - (int) $this->d('threshold', 80)) . '% מההודעות';
    }

    protected function viewName(): string
    {
        return 'quotaAlert';
    }
}
