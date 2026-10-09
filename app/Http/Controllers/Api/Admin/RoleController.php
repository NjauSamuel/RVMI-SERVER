<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();

        $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', User::class)
            ->select($roleKey, DB::raw('count(*) as aggregate'))
            ->groupBy($roleKey)
            ->pluck('aggregate', $roleKey);

        $roles = $roles->map(function (Role $role) use ($counts) {
            $role->users_count = (int) ($counts[$role->id] ?? 0);

            return $this->toArray($role);
        });

        return response()->json([
            'data' => $roles,
            'available_permissions' => Permissions::all(),
            'built_in_roles' => RolePermissionSeeder::$builtIn,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        if (in_array($data['name'], RolePermissionSeeder::$builtIn, true)) {
            return response()->json(['message' => 'That role name is reserved.'], 422);
        }

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return response()->json(['data' => $this->toArray($role)], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if (in_array($role->name, RolePermissionSeeder::$builtIn, true)) {
            return response()->json(['message' => 'Built-in roles cannot be edited.'], 422);
        }

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);

        return response()->json(['data' => $this->toArray($role->fresh('permissions'))]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, RolePermissionSeeder::$builtIn, true)) {
            return response()->json(['message' => 'Built-in roles cannot be deleted.'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /** @return array<string, mixed> */
    private function toArray(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'is_built_in' => in_array($role->name, RolePermissionSeeder::$builtIn, true),
            'permissions' => $role->permissions->pluck('name'),
            'users_count' => $role->users_count ?? null,
        ];
    }
}
