<?php

namespace App\Mail\V2;

class SendHeadsUpMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'בעוד שעה יוצאת הודעה ל־' . (int) $this->d('recipients') . ' אורחים';
    }

    protected function viewName(): string
    {
        return 'sendHeadsUp';
    }
}
