<?php

namespace App\Mail\V2;

class SeatingNudgeMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'אפשר להתחיל לתכנן את סידור הישיבה';
    }

    protected function viewName(): string
    {
        return 'seatingNudge';
    }
}
