<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $table = 'event_analytics_events';

    protected $fillable = [
        'event_id',
        'device_id',
        'name',
        'meta',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
