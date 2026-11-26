<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventEntitlement extends Model
{
    protected $table = 'event_entitlements';

    protected $fillable = ['event_id', 'seating_unlocked_at', 'seating_purchase_id', 'seating_ai_extra_runs'];

    protected $casts = ['seating_unlocked_at' => 'datetime'];
}
