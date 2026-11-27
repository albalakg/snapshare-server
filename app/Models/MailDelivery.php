<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailDelivery extends Model
{
    protected $table = 'mail_deliveries';

    protected $fillable = [
        'mail_key', 'mailable', 'recipient', 'category', 'dedupe_key', 'event_id', 'status', 'send_at', 'sent_at', 'payload', 'error',
    ];

    protected $casts = [
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    protected $hidden = ['payload'];
}
