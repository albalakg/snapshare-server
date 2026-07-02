<?php

namespace App\Services\Enums;

class IcebreakerStatusEnum extends BaseEnum
{
    const DISABLED = 0,
          UPCOMING = 1,
          ACTIVE   = 2,
          COOLDOWN = 3,
          ARCHIVED = 4;
}
