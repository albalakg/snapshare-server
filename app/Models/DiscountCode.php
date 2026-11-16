<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountCode extends Model
{
    protected $fillable = [
        'code',
        'percent',
        'status',
    ];

    protected $casts = [
        'percent' => 'integer',
        'status' => 'integer',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class, 'discount_code_id', 'id');
    }
}
