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
        'theme',
        'pace',
        'teams_mode',
        'team_labels',
        'speed_bonus',
        'prize_text',
        'reveal_seconds',
        'default_time',
        'default_points',
        'current_question_id',
        'current_phase',
        'question_started_at',
        'starts_at',
        'results_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'status' => 'integer',
        'team_labels' => 'array',
        'speed_bonus' => 'boolean',
        'reveal_seconds' => 'integer',
        'default_time' => 'integer',
        'default_points' => 'integer',
        'starts_at' => 'datetime',
        'results_at' => 'datetime',
        'question_started_at' => 'datetime',
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

    public function currentQuestion()
    {
        return $this->belongsTo(TriviaQuestion::class, 'current_question_id', 'id');
    }
}
