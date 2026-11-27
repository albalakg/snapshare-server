<?php

namespace App\Mail\V2;

class RepliesReviewMail extends SnapMail
{
    public function mailSubject(): string
    {
        return (int) $this->d('count') . ' תגובות מאורחים מחכות לבדיקה';
    }

    protected function viewName(): string
    {
        return 'repliesReview';
    }
}
