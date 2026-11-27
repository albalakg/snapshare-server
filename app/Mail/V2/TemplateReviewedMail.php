<?php

namespace App\Mail\V2;

class TemplateReviewedMail extends SnapMail
{
    public function mailSubject(): string
    {
        return $this->d('status') === 'approved' ? 'התבנית ״' . $this->d('template_title') . '״ אושרה' : 'התבנית ״' . $this->d('template_title') . '״ נדחתה';
    }

    protected function viewName(): string
    {
        return 'templateReviewed';
    }
}
