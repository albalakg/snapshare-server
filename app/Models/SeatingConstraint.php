<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeatingConstraint extends Model
{
    protected $fillable = ['event_id', 'type', 'guest_ids', 'note'];

    protected $casts = [
        'guest_ids' => 'array',
    ];
}
