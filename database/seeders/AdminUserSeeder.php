<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('CICD_ADMIN_EMAIL', 'admin@lcportal.cloud');
        $password = env('CICD_ADMIN_PASSWORD', 'password');
        $name = env('CICD_ADMIN_NAME', 'CICD Admin');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );
    }
}
