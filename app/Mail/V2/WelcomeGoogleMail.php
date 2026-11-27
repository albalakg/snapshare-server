<?php

namespace App\Mail\V2;

class WelcomeGoogleMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'ברוכים הבאים ל־SnapShare';
    }

    protected function viewName(): string
    {
        return 'welcomeGoogle';
    }
}
