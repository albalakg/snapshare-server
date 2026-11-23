<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TriviaQuestion extends Model
{
    protected $fillable = [
        'event_id',
        'prompt',
        'type',
        'fun_fact',
        'media_asset_id',
        'config',
        'sort_order',
        'time_limit_seconds',
        'max_points',
        'requires_approval',
    ];

    protected $casts = [
        'config' => 'array',
        'sort_order' => 'integer',
        'time_limit_seconds' => 'integer',
        'max_points' => 'integer',
        'requires_approval' => 'boolean',
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

    public function mediaAsset()
    {
        return $this->belongsTo(EventAsset::class, 'media_asset_id', 'id');
    }
}
