<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            return;
        }

        $admin = User::firstOrCreate(
            [
                'email' => $email,
            ],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make($password),
            ]
        );

        $admin->assignRole('admin');
    }
}
