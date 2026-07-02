<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class IcebreakerMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'profile_low_id',
        'profile_high_id',
    ];

    public function profileLow()
    {
        return $this->belongsTo(IcebreakerProfile::class, 'profile_low_id');
    }

    public function profileHigh()
    {
        return $this->belongsTo(IcebreakerProfile::class, 'profile_high_id');
    }
}
