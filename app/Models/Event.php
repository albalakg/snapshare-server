<?php

namespace App\Models;

use App\Services\Enums\StatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Event extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $hidden = [
        'order_id',
    ];

    protected $appends = ['fullPath'];

    protected $fillable = ['status'];

    public function assets()
    {
        return $this->hasMany(EventAsset::class, 'event_id', 'id')
            ->where('status', StatusEnum::ACTIVE);
    }

    public function getFullPathAttribute()
    {
        return config('app.storage_url') . '/' . $this->image;  
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function config()
    {
        return $this->hasOne(EventConfig::class, 'event_id', 'id');
    }

    public function order()
    {
        return $this->hasOne(Order::class, 'id', 'order_id');
    }

    public function activeDownloadProcess()
    {
        return $this->hasOne(EventAssetDownload::class, 'event_id', 'id')
                    ->where('status', '!=', StatusEnum::INACTIVE);
    }

    public function downloadProcesses()
    {
        return $this->hasMany(EventAssetDownload::class, 'event_id', 'id');
    }

    /**
     * @return bool
     */
    public function isInactive(): bool
    {
        return $this->status === StatusEnum::INACTIVE;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === StatusEnum::ACTIVE;
    }

    /**
     * @return bool
     */
    public function isReady(): bool
    {
        return $this->status === StatusEnum::READY;
    }

    /**
     * @return bool
     */
    public function isInProgress(): bool
    {
        return $this->status === StatusEnum::IN_PROGRESS;
    }

    /**
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === StatusEnum::PENDING;
    }
}
