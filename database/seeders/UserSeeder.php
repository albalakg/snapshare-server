<?php

namespace Database\Seeders;

use App\Services\Enums\RoleEnum;
use Illuminate\Database\Seeder;
use App\Services\Enums\StatusEnum;
use App\Services\Users\UserService;
use Illuminate\Foundation\Auth\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user_service = new UserService();
        
        $users = [
            [
                'role_id'       => RoleEnum::ADMIN_ID,
                'first_name'    => 'Admin',
                'last_name'     => 'Test',
                'email'         => 'admin@livealbums.com',
                'password'      => '123qweQWE',
                'status'        => StatusEnum::ACTIVE,
            ],
        ];
        
        foreach($users AS $user_data) {
            if(!User::where('email', $user_data['email'])->exists()) {
                $user_service->createUser($user_data);
            }
        }
    }
}
