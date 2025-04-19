<?php

namespace App\Models;

use App\Services\Enums\EventAssetTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EventAsset extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $appends = ['fullPath', 'type'];

    protected $hidden = ['asset_type'];

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
}
