<?php

namespace App\Mail\V2;

class SetupNudgeMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'עוד ' . (int) $this->d('missing_count', 1) . ' צעדים והאירוע מוכן';
    }

    protected function viewName(): string
    {
        return 'setupNudge';
    }
}
