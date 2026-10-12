<?php

namespace App\Services\Push;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One approval push, then further approvals wait 30 minutes and leave
 * as a single follow-up. The follow-up is flushed by the five-minute cron
 * because this host has no queue worker.
 */
class ApprovalPushHold
{
    private const KEY = 'push:approval-hold';

    private const MINUTES = 30;

    public function __construct(private readonly MemberPush $push) {}

    public function note(int $count, int $actorId): void
    {
        if ($count < 1) {
            return;
        }

        try {
            $outgoing = null;

            $this->locked(function () use ($count, $actorId, &$outgoing) {
                $state = $this->state();
                $within = $this->withinHold($state);

                if ($within) {
                    $this->store(
                        (int) $state['pending'] + $count,
                        $state['last_sent_at'],
                        $this->exceptIds($state, $actorId),
                    );

                    return;
                }

                $pending = (int) $state['pending'];
                $except = $pending > 0
                    ? $this->exceptIds($state, $actorId)
                    : [$actorId];
                $total = $pending + $count;

                $this->store(0, now()->toIso8601String(), $except);
                $outgoing = ['count' => $total, 'except' => $except];
            });

            if (is_array($outgoing)) {
                $this->sendLater($outgoing['count'], $outgoing['except']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function flush(): void
    {
        try {
            $outgoing = null;

            $this->locked(function () use (&$outgoing) {
                $state = $this->state();
                $pending = (int) $state['pending'];

                if ($pending < 1 || $this->withinHold($state)) {
                    return;
                }

                $except = array_values(array_map('intval', $state['except_ids']));
                $this->store(0, now()->toIso8601String(), $except);
                $outgoing = ['count' => $pending, 'except' => $except];
            });

            if (is_array($outgoing)) {
                $this->pushCount($outgoing['count'], $outgoing['except']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array{pending: int, last_sent_at: ?string, except_ids: list<int>}  $state
     */
    private function withinHold(array $state): bool
    {
        if (! is_string($state['last_sent_at']) || $state['last_sent_at'] === '') {
            return false;
        }

        return Carbon::parse($state['last_sent_at'])->greaterThan(now()->subMinutes(self::MINUTES));
    }

    /**
     * @param  list<int>  $exceptUserIds
     */
    private function sendLater(int $count, array $exceptUserIds): void
    {
        app()->terminating(function () use ($count, $exceptUserIds) {
            try {
                $this->pushCount($count, $exceptUserIds);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * @param  list<int>  $exceptUserIds
     */
    private function pushCount(int $count, array $exceptUserIds): void
    {
        $body = $count === 1 ? '1 new photo' : $count.' new photos';

        $this->push->send('New photos', $body, [
            'type' => 'media_approved',
        ], $exceptUserIds);
    }

    /**
     * @param  array{pending: int, last_sent_at: ?string, except_ids: list<int>}  $state
     * @return list<int>
     */
    private function exceptIds(array $state, int $actorId): array
    {
        $ids = array_map('intval', $state['except_ids']);
        $ids[] = $actorId;

        return array_values(array_unique($ids));
    }

    /**
     * @return array{pending: int, last_sent_at: ?string, except_ids: list<int>}
     */
    private function state(): array
    {
        $raw = Cache::get(self::KEY, []);
        if (! is_array($raw)) {
            $raw = [];
        }

        return [
            'pending' => (int) ($raw['pending'] ?? 0),
            'last_sent_at' => is_string($raw['last_sent_at'] ?? null) ? $raw['last_sent_at'] : null,
            'except_ids' => is_array($raw['except_ids'] ?? null) ? array_values($raw['except_ids']) : [],
        ];
    }

    /**
     * @param  list<int>  $exceptIds
     */
    private function store(int $pending, ?string $lastSentAt, array $exceptIds): void
    {
        Cache::put(self::KEY, [
            'pending' => $pending,
            'last_sent_at' => $lastSentAt,
            'except_ids' => $exceptIds,
        ], now()->addHours(3));
    }

    private function locked(\Closure $callback): void
    {
        try {
            $lock = Cache::lock(self::KEY.':lock', 10);
            $lock->block(5);
        } catch (\Throwable) {
            $callback();

            return;
        }

        try {
            $callback();
        } finally {
            $lock->release();
        }
    }
}
