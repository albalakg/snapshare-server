<?php

namespace App\Mail\V2;

class HubPublishedMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'עמוד האירוע שלכם באוויר';
    }

    protected function viewName(): string
    {
        return 'hubPublished';
    }
}
