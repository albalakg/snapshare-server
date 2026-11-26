<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whatsapp_audit_logs';

    protected $fillable = ['event_id', 'send_id', 'guest_id', 'user_id', 'action', 'changes', 'affected_pending', 'locked_unchanged'];

    protected $casts = [
        'changes' => 'array',
    ];
}
