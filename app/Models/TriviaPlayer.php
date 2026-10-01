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
        'score',
        'finished_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'finished_at' => 'datetime',
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
