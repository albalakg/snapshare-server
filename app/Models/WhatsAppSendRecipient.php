<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSendRecipient extends Model
{
    public const DONE = ['sent', 'delivered', 'read'];

    protected $table = 'whatsapp_send_recipients';

    protected $fillable = [
        'send_id', 'event_id', 'guest_id', 'status', 'channel', 'claimed_version', 'content_version_sent', 'locked', 'credited',
        'provider_message_id', 'skip_reason', 'error', 'sent_at', 'delivered_at', 'read_at', 'replied_at',
    ];

    protected $casts = [
        'locked'       => 'boolean',
        'credited'     => 'boolean',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
        'replied_at'   => 'datetime',
    ];
}
