<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

class EventAssetTypeEnum extends BaseEnum
{
  const VIDEO = 'video',
        VIDEO_ID = 1,
        IMAGE = 'image',
        IMAGE_ID = 2;
}