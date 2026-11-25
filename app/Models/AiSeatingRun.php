<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSeatingRun extends Model
{
    protected $fillable = [
        'event_id', 'user_id', 'engine', 'provider', 'model', 'prompt_version', 'status', 'base_revision',
        'options', 'proposal', 'unplaced', 'warnings', 'tokens_in', 'tokens_out', 'cost_usd',
        'error_message', 'applied_at',
    ];

    protected $casts = [
        'options' => 'array',
        'proposal' => 'array',
        'unplaced' => 'array',
        'warnings' => 'array',
        'cost_usd' => 'float',
        'applied_at' => 'datetime',
    ];
}
