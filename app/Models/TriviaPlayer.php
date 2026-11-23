<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TriviaPlayer extends Model
{
    protected $fillable = [
        'event_id',
        'session_token',
        'nickname',
        'avatar_path',
        'team',
        'score',
        'finished_at',
        'device_id',
        'kicked_at',
        'rejoin_blocked',
    ];

    protected $casts = [
        'score' => 'integer',
        'finished_at' => 'datetime',
        'kicked_at' => 'datetime',
        'rejoin_blocked' => 'boolean',
    ];

    public function answers()
    {
        return $this->hasMany(TriviaAnswer::class, 'player_id', 'id');
    }

    public function game()
    {
        return $this->belongsTo(TriviaGame::class, 'event_id', 'event_id');
    }
}
