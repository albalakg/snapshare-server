<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Role;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'first_name',
        'last_name',
        'password',
        'status'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Encrypt the password
     *
     * @param string $password
     * @return void
    */
    public function setPasswordAttribute(string $password)
    {
        $this->attributes['password'] = bcrypt($password);
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'user_id', 'id');
    }

    public function role()
    {
        return $this->hasOne(Role::class, 'id', 'role_id');
    }

    public function order()
    {
        return $this->hasOne(Order::class, 'user_id', 'id')
                    ->where('status', StatusEnum::ACTIVE);
    }
                    
    /**
     * @return bool
    */
    public function isAdmin(): bool
    {
        return $this->role_id === RoleEnum::ADMIN_ID;
    }
                    
    /**
     * @return bool
    */
    public function isNormalUser(): bool
    {
        return $this->role_id === RoleEnum::USER_ID;
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
    public function isInactive(): bool
    {
        return $this->status === StatusEnum::INACTIVE;
    }
    
    /**
     * @return bool
    */
    public function isPending(): bool
    {
        return $this->status === StatusEnum::PENDING;
    }
    
    /**
     * @return string
    */
    public function getRoleName(): string
    {
        return RoleEnum::getNameById($this->role_id);
    }
    
    /**
     * @return string
    */
    public function getFullName(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}
