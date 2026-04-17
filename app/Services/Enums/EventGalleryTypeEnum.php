<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

class EventGalleryTypeEnum extends BaseEnum
{
  const SINGLE_GALLERY = 'EventGallerySingle',
        RANDOM_GALLERY = 'EventGalleryRandom',        
        SPLIT_GALLERY = 'EventGallerySplitScreen';
}