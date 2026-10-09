<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->toArray($user));

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'unique:users,email'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $temporaryPassword = Str::random(16);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($temporaryPassword),
            'is_active' => true,
        ]);

        $user->assignRole($data['role']);

        // No outbound mail pipeline assumed yet on shared hosting - the admin
        // relays the temporary password/Google-email instruction to the
        // member directly. Returned once, never stored in the clear.
        return response()->json([
            'data' => $this->toArray($user),
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }

        if (array_key_exists('is_active', $data)) {
            $user->update(['is_active' => $data['is_active']]);
            if (! $user->is_active) {
                $user->tokens()->delete();
            }
        }

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json(['data' => $this->toArray($user->fresh('roles'))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->is($user)) {
            abort(403, 'You cannot delete the account you are signed in as.');
        }

        $name = $user->name;
        $email = $user->email;
        $user->tokens()->delete();
        $user->delete();

        AuditLog::record(
            $request->user(),
            'user.deleted',
            $user,
            "Deleted {$name} ({$email}).",
        );

        return response()->json(['message' => 'User deleted.']);
    }

    /** @return array<string, mixed> */
    private function toArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'is_active' => $user->is_active,
            'roles' => $user->roles->pluck('name'),
            'created_at' => $user->created_at,
        ];
    }
}
