<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

class EventGalleryTypeEnum extends BaseEnum
{
    const KEN_BURNS_GALLERY = 'EventGalleryKenBurns',
        SINGLE_GALLERY = 'EventGallerySingle',
        RANDOM_GALLERY = 'EventGalleryRandom',
        SPLIT_GALLERY = 'EventGallerySplitScreen',
        GRID_GALLERY = 'EventGalleryGrid3X3',
        SPOTLIGHT_GALLERY = 'EventGallerySpotlight',
        MOSAIC_GALLERY = 'EventGalleryMosaic',
        MASONRY_GALLERY = 'EventGalleryMasonry',
        POLAROID_GALLERY = 'EventGalleryPolaroid',
        FILMSTRIP_GALLERY = 'EventGalleryFilmstrip',
        FADE_GALLERY = 'EventGalleryFadeShow',
        HERO_GRID_GALLERY = 'EventGalleryHeroGrid',
        PAIR_GALLERY = 'EventGallerySplitPair';

    /**
     * Allowed displayed_gallery component names (max 30 chars each).
     *
     * @return string[]
     */
    public static function allowedValues(): array
    {
        return [
            self::KEN_BURNS_GALLERY,
            self::SINGLE_GALLERY,
            self::RANDOM_GALLERY,
            self::SPLIT_GALLERY,
            self::GRID_GALLERY,
            self::SPOTLIGHT_GALLERY,
            self::MOSAIC_GALLERY,
            self::MASONRY_GALLERY,
            self::POLAROID_GALLERY,
            self::FILMSTRIP_GALLERY,
            self::FADE_GALLERY,
            self::HERO_GRID_GALLERY,
            self::PAIR_GALLERY,
        ];
    }
}
