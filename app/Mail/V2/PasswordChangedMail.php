<?php

namespace App\Mail\V2;

class PasswordChangedMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'הסיסמה שלכם שונתה';
    }

    protected function viewName(): string
    {
        return 'passwordChanged';
    }
}
