<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        if (! User::query()->where('email', 'admin@rvmi.church')->exists()) {
            $admin = User::factory()->create([
                'name' => 'RVMI Admin',
                'email' => 'admin@rvmi.church',
                'password' => Hash::make('password'),
            ]);
            $admin->assignRole('Senior Bishop');
        }
    }
}
