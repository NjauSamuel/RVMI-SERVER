<?php

namespace App\Services\Push;

use App\Models\Event;
use App\Models\User;

/**
 * Fan-out to signed-in members. Never throws: a push failure must not
 * fail the comment, upload, or approval that triggered it.
 */
class MemberPush
{
    public function __construct(private readonly FcmSender $fcm) {}

    /**
     * @param  array<string, string>  $data
     * @param  list<int>  $exceptUserIds
     */
    public function send(
        string $title,
        string $body,
        array $data = [],
        array $exceptUserIds = [],
        ?Event $privateEvent = null,
    ): void {
        try {
            $recipients = User::query()
                ->where('is_active', true)
                ->whereNotNull('fcm_token')
                ->when($exceptUserIds !== [], fn ($query) => $query->whereNotIn('id', $exceptUserIds))
                ->when($privateEvent?->isPrivate(), function ($query) {
                    $query->where(function ($query) {
                        $query->whereHas('roles.permissions', fn ($q) => $q->where('name', 'view-private-media'))
                            ->orWhereHas('permissions', fn ($q) => $q->where('name', 'view-private-media'));
                    });
                })
                ->get();

            foreach ($recipients as $user) {
                if (! is_string($user->fcm_token) || $user->fcm_token === '') {
                    continue;
                }

                $this->fcm->send($user->fcm_token, $title, $body, $data);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
