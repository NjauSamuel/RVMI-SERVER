<?php

namespace App\Services\Auth;

use Illuminate\Validation\ValidationException;

interface GoogleIdTokenVerifier
{
    /**
     * Verify a Google ID token issued to the Flutter app.
     *
     * @throws ValidationException
     */
    public function verify(string $idToken): GoogleIdentity;
}
