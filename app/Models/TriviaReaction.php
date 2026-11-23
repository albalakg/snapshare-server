<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TriviaReaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'player_id',
        'kind',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function player()
    {
        return $this->belongsTo(TriviaPlayer::class, 'player_id', 'id');
    }
}
