<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class IcebreakerConfig extends Model
{
    use HasFactory;

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'status',
        'allowed_intents',
        'started_at',
        'ended_at',
        'purged_at',
    ];

    protected $casts = [
        'allowed_intents' => 'array',
        'started_at'      => 'datetime',
        'ended_at'        => 'datetime',
        'purged_at'       => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function profiles()
    {
        return $this->hasMany(IcebreakerProfile::class, 'event_id', 'event_id');
    }
}
