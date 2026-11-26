<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'event_id', 'key', 'base_key', 'title', 'stage', 'category', 'language', 'header', 'body', 'footer',
        'buttons', 'meta_name', 'meta_template_id', 'meta_status', 'meta_reason', 'is_system', 'submitted_at', 'reviewed_at',
    ];

    protected $casts = [
        'buttons'      => 'array',
        'is_system'    => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_at'  => 'datetime',
    ];
}
