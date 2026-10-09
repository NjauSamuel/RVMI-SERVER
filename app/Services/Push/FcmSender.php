<?php

namespace App\Services\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Sends pushes via the FCM HTTP v1 API using a Firebase service account.
 * Deliberately simple/synchronous - calls happen inside a cron command tick,
 * never from a long-running worker.
 */
class FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

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
            Log::info('FCM not configured; skipping push.', ['title' => $title]);

            return false;
        }

        try {
            $credentials = new ServiceAccountCredentials(self::SCOPE, config('services.fcm.credentials_path'));
            $token = $credentials->fetchAuthToken()['access_token'] ?? null;

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
}
