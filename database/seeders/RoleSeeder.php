<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Services\Enums\RoleEnum;
use Illuminate\Database\Seeder;
use App\Services\Enums\StatusEnum;
use App\Services\Users\UserService;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
    */
    public function run(): void
    {
        $roles = collect(RoleEnum::getAll())->map(function ($role_id, $role) {
            return [
                'id' => $role_id,
                'name' => $role
            ];
        })->toArray();
        
        Role::insert($roles);
    }
}
