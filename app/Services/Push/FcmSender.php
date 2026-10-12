<?php

namespace App\Services\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Sends pushes via the FCM HTTP v1 API using a Firebase service account.
 * One access token is reused for every device in the same fan-out.
 * A failed or unconfigured send returns false and never throws.
 */
class FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private ?string $accessToken = null;

    private bool $tokenResolved = false;

    private bool $loggedSkip = false;

    public function configured(): bool
    {
        return filled(config('services.fcm.credentials_path')) && filled(config('services.fcm.project_id'));
    }

    /**
     * @param  array<string, string>  $data
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        if (! $this->configured()) {
            if (! $this->loggedSkip) {
                Log::info('FCM not configured; skipping push.', ['title' => $title]);
                $this->loggedSkip = true;
            }

            return false;
        }

        try {
            $token = $this->accessToken();

            if (! $token) {
                return false;
            }

            $projectId = config('services.fcm.project_id');

            $client = new Client;
            $client->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'message' => [
                        'token' => $deviceToken,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $data,
                    ],
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function accessToken(): ?string
    {
        if ($this->tokenResolved) {
            return $this->accessToken;
        }

        $this->tokenResolved = true;

        try {
            $credentials = new ServiceAccountCredentials(self::SCOPE, config('services.fcm.credentials_path'));
            $this->accessToken = $credentials->fetchAuthToken()['access_token'] ?? null;
        } catch (\Throwable $e) {
            report($e);
            $this->accessToken = null;
        }

        return $this->accessToken;
    }
}
