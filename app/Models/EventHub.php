<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EventHub extends Model
{
    use HasFactory;

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'slug',
        'is_published',
        'hub_config',
    ];

    protected $casts = [
        'hub_config'    => 'array',
        'is_published'  => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
