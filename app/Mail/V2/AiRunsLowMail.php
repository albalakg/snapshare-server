<?php

namespace App\Mail\V2;

class AiRunsLowMail extends SnapMail
{
    public function mailSubject(): string
    {
        return ((int) $this->d('runs_left', 0) === 0) ? 'נגמרו ריצות ה־AI לסידור הישיבה' : 'נשארה לכם ריצת AI אחת לסידור הישיבה';
    }

    protected function viewName(): string
    {
        return 'aiRunsLow';
    }
}
