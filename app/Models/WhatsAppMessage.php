<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'event_id', 'guest_id', 'send_id', 'direction', 'kind', 'body', 'payload', 'provider_message_id', 'context_id',
        'status', 'intent', 'suggestion', 'classifier', 'confidence', 'suggestion_note', 'handled', 'read_by_host_at',
    ];

    protected $casts = [
        'body'            => 'encrypted',
        'payload'         => 'array',
        'suggestion'      => 'array',
        'read_by_host_at' => 'datetime',
    ];
}
