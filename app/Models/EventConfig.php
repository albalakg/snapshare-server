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
    ];

    protected $casts = [
        'preview_site_display_image' => 'boolean',
        'preview_site_display_name'  => 'boolean',
        'preview_site_display_date'  => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}

