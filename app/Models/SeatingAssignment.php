<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeatingAssignment extends Model
{
    protected $fillable = ['plan_id', 'table_id', 'guest_id', 'seat'];

    protected $casts = [
        'seat' => 'integer',
    ];
}
