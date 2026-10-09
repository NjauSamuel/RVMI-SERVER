<?php

namespace App\Services\Auth;

use Google\Auth\AccessToken;
use Illuminate\Validation\ValidationException;

final class GoogleApiClientIdTokenVerifier implements GoogleIdTokenVerifier
{
    public function verify(string $idToken): GoogleIdentity
    {
        /** @var array<int, string> $audiences */
        $audiences = config('services.google.client_ids', []);

        if ($audiences === []) {
            throw ValidationException::withMessages([
                'id_token' => 'Google sign-in is not configured yet.',
            ]);
        }

        $payload = null;
        $accessToken = new AccessToken;

        foreach ($audiences as $audience) {
            $verified = $accessToken->verify($idToken, [
                'audience' => $audience,
            ]);

            if (is_array($verified)) {
                $payload = $verified;
                break;
            }
        }

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'id_token' => 'Google sign-in could not be verified. Please try again.',
            ]);
        }

        $id = isset($payload['sub']) ? (string) $payload['sub'] : '';
        $email = isset($payload['email']) ? strtolower(trim((string) $payload['email'])) : '';
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $name = isset($payload['name']) ? (string) $payload['name'] : null;
        $avatar = isset($payload['picture']) ? (string) $payload['picture'] : null;

        if ($id === '' || $email === '' || ! $emailVerified) {
            throw ValidationException::withMessages([
                'id_token' => 'Google did not return a verified email for this account.',
            ]);
        }

        return new GoogleIdentity($id, $email, $name, $avatar !== '' ? $avatar : null);
    }
}
