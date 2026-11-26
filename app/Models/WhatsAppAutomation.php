<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppAutomation extends Model
{
    protected $table = 'whatsapp_automations';

    protected $fillable = ['event_id', 'key', 'template_key', 'template_id', 'title', 'enabled', 'offset_days', 'send_time', 'segment', 'send_id'];

    protected $casts = [
        'enabled'     => 'boolean',
        'offset_days' => 'integer',
        'segment'     => 'array',
    ];
}
