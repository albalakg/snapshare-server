<?php

namespace App\Models;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'token',
        'supplier_id',
        'payment_page_link',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function events()
    {
        return $this->hasMany(Event::class, 'order_id', 'id');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class, 'id', 'subscription_id')
            ->select('id', 'name', 'price', 'files_allowed', 'events_allowed', 'storage_time');
    }
}
