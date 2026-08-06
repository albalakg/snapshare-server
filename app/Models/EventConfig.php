<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EventConfig extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'preview_site_display_image',
        'preview_site_display_name',
        'preview_site_display_date',
        'preview_guests_assets_in_gallery',
        'preview_owners_assets_in_gallery',
        'preview_qr_in_gallery',
        'displayed_gallery',
        'video_upload_enabled',
        'qr_card_design',
        'qr_card_text',
        'preview_link_to_album_page_from_upload_page',
    ];

    protected $casts = [
        'preview_site_display_image'                    => 'boolean',
        'preview_site_display_name'                     => 'boolean',
        'preview_site_display_date'                     => 'boolean',
        'preview_guests_assets_in_gallery'              => 'boolean',
        'preview_owners_assets_in_gallery'              => 'boolean',
        'preview_qr_in_gallery'                         => 'boolean',
        'video_upload_enabled'                          => 'boolean',
        'preview_link_to_album_page_from_upload_page'   => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}

