<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSend extends Model
{
    public const EDITABLE = ['scheduled', 'paused', 'sending', 'review'];

    protected $table = 'whatsapp_sends';

    protected $fillable = [
        'event_id', 'user_id', 'source', 'automation_id', 'template_id', 'template_key', 'title', 'category', 'header', 'body',
        'footer', 'buttons', 'segment', 'audience_mode', 'status', 'scheduled_at', 'override_quiet', 'version', 'content_version',
        'paused_at', 'started_at', 'completed_at', 'cancelled_at',
    ];

    protected $casts = [
        'buttons'         => 'array',
        'segment'         => 'array',
        'override_quiet'  => 'boolean',
        'version'         => 'integer',
        'content_version' => 'integer',
        'scheduled_at'    => 'datetime',
        'paused_at'       => 'datetime',
        'started_at'      => 'datetime',
        'completed_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
    ];

    public function recipients()
    {
        return $this->hasMany(WhatsAppSendRecipient::class, 'send_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }
}
