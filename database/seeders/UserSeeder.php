<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Your personal super admin account — full dashboard + /admin panel
        User::create([
            'name'              => 'Super Admin',
            'email'             => 'superadmin@nexstage.dev',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
            'is_super_admin'    => true,
            'last_login_at'     => now()->subMinutes(30),
        ]);

        // Regular store owner on a paid (Growth) plan
        User::create([
            'name'              => 'Store Owner',
            'email'             => 'owner@nexstage.dev',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
            'is_super_admin'    => false,
            'last_login_at'     => now()->subHours(2),
        ]);

        // Regular store owner on trial (tests billing gates at expiry)
        User::create([
            'name'              => 'Trial Owner',
            'email'             => 'trial@nexstage.dev',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
            'is_super_admin'    => false,
            'last_login_at'     => now()->subHours(5),
        ]);

        // Member on the paid workspace (limited permissions)
        User::create([
            'name'              => 'Team Member',
            'email'             => 'member@nexstage.dev',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
            'is_super_admin'    => false,
            'last_login_at'     => now()->subDays(1),
        ]);

        // Clean super admin — no workspace, no fake data.
        // Use this account to connect real integrations and import live data.
        // Lands on /onboarding after login.
        User::create([
            'name'              => 'Admin',
            'email'             => 'admin@nexstage.dev',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
            'is_super_admin'    => true,
            'last_login_at'     => null,
        ]);
    }
}
