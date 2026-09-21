<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Roles must exist before anyone can be given one. Registration is
        // closed, so this seeded account is the only way in on a fresh install.
        $this->call(RoleSeeder::class);

        $email = config('auth.admin_email');
        $password = config('auth.admin_password');
        $name = config('auth.admin_name');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );

        $user->assignRole('admin');
    }
}
