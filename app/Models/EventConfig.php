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
    ];

    protected $casts = [
        'preview_site_display_image'        => 'boolean',
        'preview_site_display_name'         => 'boolean',
        'preview_site_display_date'         => 'boolean',
        'preview_guests_assets_in_gallery'  => 'boolean',
        'preview_owners_assets_in_gallery'  => 'boolean',
        'preview_qr_in_gallery'             => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}

