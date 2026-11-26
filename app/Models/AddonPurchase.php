<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddonPurchase extends Model
{
    protected $table = 'addon_purchases';

    protected $fillable = [
        'uid', 'event_id', 'user_id', 'product', 'quantity', 'unit_price', 'amount', 'currency', 'status', 'provider',
        'page_request_uid', 'payment_page_link', 'approval_number', 'provider_payload', 'failure_reason', 'paid_at', 'credited_at',
    ];

    protected $casts = [
        'provider_payload' => 'array',
        'paid_at'          => 'datetime',
        'credited_at'      => 'datetime',
        'unit_price'       => 'float',
        'amount'           => 'float',
    ];
}
