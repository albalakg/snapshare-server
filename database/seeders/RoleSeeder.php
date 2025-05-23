<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Services\Enums\RoleEnum;
use Illuminate\Database\Seeder;

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
