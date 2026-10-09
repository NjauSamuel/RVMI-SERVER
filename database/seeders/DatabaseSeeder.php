<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // The User model hashes `password` itself. Passing Hash::make() here
        // stores a hash of a hash, so "password" never matches.
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@rvmi.church'],
            [
                'name' => 'RVMI Admin',
                'password' => 'password',
                'is_active' => true,
            ],
        );
        $admin->assignRole('Senior Bishop');
    }
}

