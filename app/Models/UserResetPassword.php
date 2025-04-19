<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserResetPassword extends Model
{
    use HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    public function user()
    {
        return $this->hasOne(User::class, 'email', 'email');
    }
}
