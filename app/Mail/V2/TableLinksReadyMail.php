<?php

namespace App\Mail\V2;

class TableLinksReadyMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'הישיבה מוכנה: שלחו לאורחים את השולחן שלהם';
    }

    protected function viewName(): string
    {
        return 'tableLinksReady';
    }
}
