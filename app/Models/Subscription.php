<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    public function orders()
    {
        return $this->hasMany(Order::class, 'subscription_id', 'id');
    }
}
