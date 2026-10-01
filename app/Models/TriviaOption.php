<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TriviaOption extends Model
{
    protected $fillable = [
        'question_id',
        'label',
        'is_correct',
        'sort_order',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    public function question()
    {
        return $this->belongsTo(TriviaQuestion::class, 'question_id', 'id');
    }
}
