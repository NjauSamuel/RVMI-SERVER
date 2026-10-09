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

        // Password stays empty. The column is nullable, and password login
        // already rejects an account with no password. This account signs in
        // with Google only, so no secret is stored in the repo.
        $admin = User::query()->updateOrCreate(
            ['email' => 'njaus602@gmail.com'],
            [
                'name' => 'Samuel Njau',
                'password' => null,
                'is_active' => true,
            ],
        );
        $admin->syncRoles(['Developer']);
    }
}

