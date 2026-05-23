<?php

namespace App\Models;

use App\Services\Enums\EventAssetTypeEnum;
use App\Services\Enums\StatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EventAsset extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $appends = ['fullPath', 'type'];

    protected $hidden = ['asset_type'];

    protected $casts = [
        'moderation_labels' => 'array',
        'is_displayed' => 'boolean',
    ];

    protected $fillable = ['status'];

    public function getTypeAttribute()
    {
        return EventAssetTypeEnum::getNameById($this->asset_type);  
    }

    public function getFullPathAttribute()
    {
        return config('app.storage_url') . '/' . $this->path;  
    }

    public function event()
    {
        return $this->hasOne(Event::class, 'id', 'event_id');
    }

    public function scopeVisibleInGallery(Builder $query): Builder
    {
        return $query->where('status', StatusEnum::ACTIVE)
            ->where('is_displayed', true);
    }
}
