<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use App\Services\Push\FcmSender;
use Illuminate\Console\Command;

/**
 * Runs every few minutes via cron. Batches unsent comments per event into a
 * single push per event ("3 new comments on Graduation") instead of one
 * notification per comment - keeps the app from feeling like an
 * IG-style like/comment feed.
 */
class DispatchCommentDigestCommand extends Command
{
    protected $signature = 'notifications:dispatch-digest';

    protected $description = 'Send one batched push per event for unsent comments.';

    public function __construct(private readonly FcmSender $fcm)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pendingEventIds = EventComment::query()
            ->where('notified', false)
            ->distinct()
            ->pluck('event_id');

        foreach ($pendingEventIds as $eventId) {
            $this->digestEvent($eventId);
        }

        $this->info('Dispatched digest for '.$pendingEventIds->count().' event(s).');

        return self::SUCCESS;
    }

    private function digestEvent(int $eventId): void
    {
        $event = Event::query()->find($eventId);
        if (! $event) {
            return;
        }

        $comments = EventComment::query()
            ->where('event_id', $eventId)
            ->where('notified', false)
            ->get();

        if ($comments->isEmpty()) {
            return;
        }

        $count = $comments->count();
        $title = $event->title;
        $body = $count === 1
            ? '1 new comment on '.$title
            : $count.' new comments on '.$title;

        $recipients = User::query()
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->when($event->isPrivate(), function ($query) {
                // Only users who can view private media should be notified
                // about a private event's discussion.
                $query->whereHas('roles.permissions', fn ($q) => $q->where('name', 'view-private-media'))
                    ->orWhereHas('permissions', fn ($q) => $q->where('name', 'view-private-media'));
            })
            ->get();

        foreach ($recipients as $user) {
            $this->fcm->send($user->fcm_token, $title, $body, [
                'type' => 'event_comment_digest',
                'event_id' => (string) $event->id,
            ]);
        }

        EventComment::query()
            ->whereIn('id', $comments->pluck('id'))
            ->update(['notified' => true]);
    }
}
