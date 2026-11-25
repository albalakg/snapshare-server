<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventGuestGroup extends Model
{
    protected $table = 'event_guest_groups';

    protected $fillable = ['event_id', 'name', 'side', 'color', 'sort_order'];

    public function guests()
    {
        return $this->hasMany(EventGuest::class, 'group_id');
    }
}
