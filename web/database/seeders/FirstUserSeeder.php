<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FirstUserSeeder extends Seeder
{
    public function run(): void
    {
            $email = 'sofia@wecode.dk';
            $password = 'password';
            $role = 'super_admin';
            $name = 'Super Admin';

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => $role,
                'email_verified_at' => now(),
            ]
        );
    }
}