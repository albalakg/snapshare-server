<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

class EventGalleryTypeEnum extends BaseEnum
{
  const SINGLE_GALLERY = 'EventSingleGallery',
        RANDOM_GALLERY = 'EventGalleryRandom',        
        SPLIT_GALLERY = 'EventGallerySplitScreen';
}