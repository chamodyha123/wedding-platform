<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            [
                'email' => 'admin@weddingmarketplace.test',
            ],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('AdminPassword123!'),
            ]
        );

        $admin->assignRole('admin');
    }
}