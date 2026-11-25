<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeatingPlan extends Model
{
    protected $fillable = ['event_id', 'canvas', 'revision', 'locked_at'];

    protected $casts = [
        'canvas' => 'array',
        'locked_at' => 'datetime',
    ];

    public function tables()
    {
        return $this->hasMany(SeatingTable::class, 'plan_id')->orderBy('sort_order')->orderBy('id');
    }

    public function assignments()
    {
        return $this->hasMany(SeatingAssignment::class, 'plan_id');
    }
}
