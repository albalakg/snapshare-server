<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

class StatusEnum extends BaseEnum
{
  const INACTIVE    = 0,
        ACTIVE      = 1,
        PENDING     = 2,
        IN_PROGRESS = 3,
        READY       = 4,
        CLOSED      = 5;
}