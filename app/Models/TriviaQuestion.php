<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TriviaQuestion extends Model
{
    protected $fillable = [
        'event_id',
        'prompt',
        'sort_order',
        'time_limit_seconds',
        'max_points',
    ];

    public function options()
    {
        return $this->hasMany(TriviaOption::class, 'question_id', 'id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function answers()
    {
        return $this->hasMany(TriviaAnswer::class, 'question_id', 'id');
    }

    public function game()
    {
        return $this->belongsTo(TriviaGame::class, 'event_id', 'event_id');
    }
}
