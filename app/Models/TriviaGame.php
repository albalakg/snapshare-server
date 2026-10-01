<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TriviaGame extends Model
{
    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'enabled',
        'status',
        'title',
        'starts_at',
        'results_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'status' => 'integer',
        'starts_at' => 'datetime',
        'results_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function questions()
    {
        return $this->hasMany(TriviaQuestion::class, 'event_id', 'event_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function players()
    {
        return $this->hasMany(TriviaPlayer::class, 'event_id', 'event_id');
    }
}
