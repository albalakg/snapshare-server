<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = ['event_id', 'guest_id', 'step', 'data', 'last_inbound_at'];

    protected $casts = [
        'data'            => 'array',
        'last_inbound_at' => 'datetime',
    ];
}
