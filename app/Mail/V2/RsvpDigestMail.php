<?php

namespace App\Mail\V2;

class RsvpDigestMail extends SnapMail
{
    public function mailSubject(): string
    {
        return (int) $this->d('new_count') . ' תשובות חדשות לאישורי ההגעה';
    }

    protected function viewName(): string
    {
        return 'rsvpDigest';
    }
}
