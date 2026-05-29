<?php

namespace App\Services\Enums;

class WhatsAppCampaignStatusEnum extends BaseEnum
{
    const PENDING   = 0;
    const QUEUED    = 1;
    const SENDING   = 2;
    const COMPLETED = 3;
    const PARTIAL   = 4;
    const FAILED    = 5;
    const CANCELLED = 6;
}
