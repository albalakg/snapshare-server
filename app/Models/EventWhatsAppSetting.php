<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventWhatsAppSetting extends Model
{
    protected $table = 'event_whatsapp_settings';

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $fillable = [
        'event_id', 'quiet_hours_enabled', 'shabbat_guard_enabled', 'sms_fallback_enabled',
        'pro_addon', 'topups', 'test_sends_used', 'variables', 'rsvp_config',
    ];

    protected $casts = [
        'quiet_hours_enabled'   => 'boolean',
        'shabbat_guard_enabled' => 'boolean',
        'sms_fallback_enabled'  => 'boolean',
        'pro_addon'             => 'boolean',
        'topups'                => 'integer',
        'test_sends_used'       => 'integer',
        'variables'             => 'array',
        'rsvp_config'           => 'array',
    ];
}
