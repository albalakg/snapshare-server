<?php

namespace App\Mail\V2;

class FirstUploadsMail extends SnapMail
{
    public function mailSubject(): string
    {
        return 'הקבצים הראשונים כבר כאן — ' . $this->d('event_name');
    }

    protected function viewName(): string
    {
        return 'firstUploads';
    }
}
