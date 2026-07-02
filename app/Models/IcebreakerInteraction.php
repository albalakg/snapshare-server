<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class IcebreakerInteraction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'actor_profile_id',
        'target_profile_id',
        'action',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function actorProfile()
    {
        return $this->belongsTo(IcebreakerProfile::class, 'actor_profile_id');
    }

    public function targetProfile()
    {
        return $this->belongsTo(IcebreakerProfile::class, 'target_profile_id');
    }
}
