<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WhatsAppCampaign extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_campaigns';

    protected $fillable = [
        'event_id',
        'user_id',
        'message',
        'send_mode',
        'scheduled_at',
        'status',
        'recipient_count',
        'sent_count',
        'failed_count',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'queued_at'    => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function campaignGuests()
    {
        return $this->hasMany(WhatsAppCampaignGuest::class, 'campaign_id');
    }

    public function guests()
    {
        return $this->belongsToMany(EventGuest::class, 'whatsapp_campaign_guests', 'campaign_id', 'guest_id')
            ->withPivot(['status', 'sent_at', 'error_message']);
    }
}
