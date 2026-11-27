<?php

namespace App\Mail\V2;

class SaveTheDateReadyMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'ה־Save the Date שלכם מוכן';
    }

    protected function viewName(): string
    {
        return 'saveTheDateReady';
    }
}
