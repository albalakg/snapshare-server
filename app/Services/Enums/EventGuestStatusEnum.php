<?php

namespace App\Services\Enums;

class EventGuestStatusEnum extends BaseEnum
{
    const INVITED   = 0,
          CONFIRMED = 1,
          DECLINED  = 2,
          MAYBE     = 3,
          CANCELLED = 4;
}
