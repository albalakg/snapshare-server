<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeatingTable extends Model
{
    protected $fillable = ['plan_id', 'label', 'shape', 'seats', 'side', 'x', 'y', 'w', 'h', 'rot', 'sort_order'];

    protected $casts = [
        'seats' => 'integer',
        'x' => 'integer',
        'y' => 'integer',
        'w' => 'integer',
        'h' => 'integer',
        'rot' => 'integer',
    ];
}
