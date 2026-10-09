<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /** @var array<int, string> Non-deletable, non-editable roles. */
    public static array $builtIn = ['Developer', 'Senior Bishop', 'Member'];

    public function run(): void
    {
        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $developer = Role::findOrCreate('Developer', 'web');
        $developer->syncPermissions(Permissions::all());

        $seniorBishop = Role::findOrCreate('Senior Bishop', 'web');
        $seniorBishop->syncPermissions(Permissions::all());

        $member = Role::findOrCreate('Member', 'web');
        $member->syncPermissions([Permissions::COMMENT]);
    }
}
