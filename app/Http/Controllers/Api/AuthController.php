<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly GoogleIdTokenVerifier $googleIdTokens) {}

    /**
     * Password login. Accounts are provisioned by an admin - there is no
     * public self-registration.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $this->assertActive($user);

        return $this->sessionResponse($user, $credentials['device_name'] ?? 'RVMI Media App');
    }

    public function loginWithGoogle(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id_token' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $google = $this->googleIdTokens->verify($payload['id_token']);

        $user = User::query()
            ->where(function ($query) use ($google) {
                $query->where('google_id', $google->id)
                    ->orWhere('email', $google->email);
            })
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'id_token' => 'No RVMI Media account found for this Google email. Please ask an admin to add you first.',
            ]);
        }

        $this->assertActive($user);

        $user->forceFill([
            'google_id' => $google->id,
            'avatar_url' => $google->avatar ?? $user->avatar_url,
        ])->save();

        return $this->sessionResponse($user, $payload['device_name'] ?? 'RVMI Media App');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function updatePushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string'],
        ]);

        $request->user()->forceFill(['fcm_token' => $data['fcm_token']])->save();

        return response()->json(['message' => 'Saved.']);
    }

    private function assertActive(User $user): void
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact an admin.',
            ]);
        }
    }

    private function sessionResponse(User $user, string $deviceName): JsonResponse
    {
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json(array_merge(
            ['token' => $token],
            $this->userPayload($user),
        ));
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'roles' => $user->getRoleNames(),
            ],
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'nav_permissions' => Permissions::navGated(),
        ];
    }
}
