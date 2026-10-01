<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TriviaAnswer extends Model
{
    protected $fillable = [
        'player_id',
        'question_id',
        'selected_option_ids',
        'is_correct',
        'points_awarded',
        'elapsed_ms',
        'served_at',
        'answered_at',
    ];

    protected $casts = [
        'selected_option_ids' => 'array',
        'is_correct' => 'boolean',
        'points_awarded' => 'integer',
        'elapsed_ms' => 'integer',
        'served_at' => 'datetime',
        'answered_at' => 'datetime',
    ];

    public function player()
    {
        return $this->belongsTo(TriviaPlayer::class, 'player_id', 'id');
    }

    public function question()
    {
        return $this->belongsTo(TriviaQuestion::class, 'question_id', 'id');
    }
}
