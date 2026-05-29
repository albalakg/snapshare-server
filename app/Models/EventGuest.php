<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Core guest record for an event. Shared identity used across features
 * (WhatsApp, table planning, payments, etc.). Feature-specific data lives
 * in dedicated tables keyed by guest_id.
 */
class EventGuest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'full_name',
        'phone',
        'phone_hash',
        'email',
        'email_hash',
        'status',
        'party_size',
        'group_key',
        'metadata',
    ];

    protected $casts = [
        'full_name' => 'encrypted',
        'phone'     => 'encrypted',
        'email'     => 'encrypted',
        'metadata'  => 'array',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function whatsappCampaignGuests()
    {
        return $this->hasMany(WhatsAppCampaignGuest::class, 'guest_id');
    }

    public function whatsappCampaigns()
    {
        return $this->belongsToMany(WhatsAppCampaign::class, 'whatsapp_campaign_guests', 'guest_id', 'campaign_id')
            ->withPivot(['status', 'sent_at', 'error_message']);
    }
}
