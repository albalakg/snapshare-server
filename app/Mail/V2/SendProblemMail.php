<?php

namespace App\Mail\V2;

class SendProblemMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'הייתה בעיה בשליחת ההודעה';
    }

    protected function viewName(): string
    {
        return 'sendProblem';
    }
}
