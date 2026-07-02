<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class IcebreakerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'user_session_token',
        'display_name',
        'avatar_url',
        'gender',
        'target_genders',
        'primary_intent',
        'bio',
        'instagram_handle',
        'whatsapp_number',
        'is_active',
    ];

    protected $casts = [
        'target_genders'   => 'array',
        'instagram_handle' => 'encrypted',
        'whatsapp_number'  => 'encrypted',
        'is_active'        => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function config()
    {
        return $this->belongsTo(IcebreakerConfig::class, 'event_id', 'event_id');
    }

    public function outgoingInteractions()
    {
        return $this->hasMany(IcebreakerInteraction::class, 'actor_profile_id');
    }

    public function incomingInteractions()
    {
        return $this->hasMany(IcebreakerInteraction::class, 'target_profile_id');
    }
}
