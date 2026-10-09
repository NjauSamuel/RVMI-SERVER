<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /** @var array<int, string> Non-deletable, non-editable roles. */
    public static array $builtIn = ['Developer', 'Senior Admin', 'Member'];

    public function run(): void
    {
        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $developer = Role::findOrCreate('Developer', 'web');
        $developer->syncPermissions(Permissions::all());

        $found = Role::query()->where('name', 'Senior Bishop')->where('guard_name', 'web')->first();

        if ($found instanceof Role) {
            $seniorAdmin = $found;
        } else {
            $created = Role::findOrCreate('Senior Admin', 'web');
            if (! $created instanceof Role) {
                throw new \RuntimeException('Could not create the Senior Admin role.');
            }
            $seniorAdmin = $created;
        }

        if ($seniorAdmin->name !== 'Senior Admin') {
            $seniorAdmin->name = 'Senior Admin';
            $seniorAdmin->save();
        }

        $seniorAdmin->syncPermissions(Permissions::all());

        $member = Role::findOrCreate('Member', 'web');
        $member->syncPermissions([Permissions::COMMENT]);
    }
}
