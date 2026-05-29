<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppCampaignGuest extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'whatsapp_campaign_guests';

    /**
     * Composite primary key: (campaign_id, guest_id).
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('campaign_id', $this->getAttribute('campaign_id'))
            ->where('guest_id', $this->getAttribute('guest_id'));
    }

    protected $fillable = [
        'campaign_id',
        'guest_id',
        'status',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'campaign_id');
    }

    public function guest()
    {
        return $this->belongsTo(EventGuest::class, 'guest_id');
    }
}
