<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventAssetDownload extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'event_assets_downloads';

    protected $fillable = [
        'event_id',
        'status',
        'event_assets',
        'path',
        'created_by'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = ['fullPath'];

    public function getFullPathAttribute()
    {
        return config('app.storage_url') . '/' . $this->path;  
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
