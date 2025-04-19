<?php

namespace App\Models;

use App\Services\Enums\StatusEnum;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
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
    public function isInProgress(): bool
    {
        return $this->status === StatusEnum::CLOSED;
    }
}
