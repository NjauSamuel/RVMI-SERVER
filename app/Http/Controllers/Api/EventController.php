<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\MediaItem;
use App\Models\Tag;
use App\Models\UserEventFavorite;
use App\Services\Media\CloudFrontUrlSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private readonly CloudFrontUrlSigner $signer) {}

    public function index(Request $request): JsonResponse
    {
        $canSeePrivate = $request->user()->can('view-private-media');

        $events = Event::query()
            ->when(! $canSeePrivate, fn ($q) => $q->where('visibility', 'public'))
            ->withCount(['approvedMedia as media_count'])
            ->with('coverMedia')
            ->orderByDesc('event_date')
            ->paginate(20);

        return response()->json($this->presentPage($request, $events));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_date' => ['nullable', 'date'],
            'visibility' => ['required', 'in:public,private'],
        ]);

        $event = Event::query()->create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->toArray($event, $this->isFavorite($request, $event))], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $event->assertVisibleTo($request->user());

        return response()->json(['data' => $this->toArray($event, $this->isFavorite($request, $event))]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_date' => ['nullable', 'date'],
            'visibility' => ['sometimes', 'in:public,private'],
            'cover_media_item_id' => ['nullable', 'integer', 'exists:media_items,id'],
        ]);

        $event->update($data);

        return response()->json(['data' => $this->toArray($event)]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $title = $event->title;
        $event->delete();

        AuditLog::record(
            $request->user(),
            'event.deleted',
            $event,
            "Deleted event {$title}.",
        );

        return response()->json(['message' => 'Deleted.']);
    }

    public function deleted(Request $request): JsonResponse
    {
        $events = Event::onlyTrashed()
            ->withCount(['approvedMedia as media_count'])
            ->with('coverMedia')
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Event $event) => $this->toArray($event));

        return response()->json(['data' => $events]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $event = Event::onlyTrashed()->findOrFail($id);
        $event->restore();

        AuditLog::record(
            $request->user(),
            'event.restored',
            $event,
            "Restored event {$event->title}.",
        );

        return response()->json(['data' => $this->toArray($event)]);
    }

    /** @return array<string, mixed> */
    private function toArray(Event $event, bool $isFavorite = false): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'event_date' => $event->event_date?->toDateString(),
            'visibility' => $event->visibility,
            'media_count' => $event->media_count ?? $event->approvedMedia()->count(),
            'is_favorite' => $isFavorite,
            'cover_thumbnail_url' => $event->coverMedia?->thumbnail_key
                ? $this->signer->signedUrl($event->coverMedia->thumbnail_key)
                : null,
            'cover_blurhash' => $event->coverMedia?->blurhash,
        ];
    }

    public function favorite(Request $request, Event $event): JsonResponse
    {
        $event->assertVisibleTo($request->user());

        UserEventFavorite::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'event_id' => $event->id,
        ]);

        return response()->json(['message' => 'Saved.']);
    }

    public function unfavorite(Request $request, Event $event): JsonResponse
    {
        UserEventFavorite::query()
            ->where('user_id', $request->user()->id)
            ->where('event_id', $event->id)
            ->delete();

        return response()->json(['message' => 'Removed.']);
    }

    public function saved(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'saved_from' => ['sometimes', 'nullable', 'date'],
            'saved_to' => ['sometimes', 'nullable', 'date'],
        ]);

        $events = $this->savedEvents($request)
            ->when($request->integer('tag_id'), function ($q, $tagId) use ($request) {
                $q->whereIn('events.id', $this->eventIdsWithTag($request, $tagId));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('events.event_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('events.event_date', '<=', $request->date('to')))
            ->when($request->filled('saved_from'), fn ($q) => $q->where('user_event_favorites.created_at', '>=', $request->date('saved_from')))
            ->when($request->filled('saved_to'), fn ($q) => $q->where('user_event_favorites.created_at', '<=', $request->date('saved_to')))
            ->withCount(['approvedMedia as media_count'])
            ->with('coverMedia')
            ->orderByDesc('user_event_favorites.created_at')
            ->get();

        return response()->json([
            'data' => $events->map(fn (Event $event) => $this->toArray($event, true)),
        ]);
    }

    /**
     * Tags and months that actually appear among this user's saved events.
     */
    public function savedFilters(Request $request): JsonResponse
    {
        $events = $this->savedEvents($request)->get();
        $eventIds = $events->pluck('id');
        $mediaIds = $eventIds->isEmpty()
            ? collect()
            : $this->visibleMedia($request)->whereIn('event_id', $eventIds)->pluck('id');

        $tags = $mediaIds->isEmpty()
            ? collect()
            : Tag::query()
                ->whereHas('media', fn ($q) => $q->whereIn('media_items.id', $mediaIds))
                ->orderByRaw("case when kind = 'person' then 0 else 1 end")
                ->orderBy('name')
                ->get(['id', 'name', 'kind']);

        $savedAt = $eventIds->isEmpty()
            ? collect()
            : UserEventFavorite::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('event_id', $eventIds)
                ->pluck('created_at');

        return response()->json([
            'data' => [
                'tags' => $tags->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'kind' => $tag->kind,
                ])->values(),
                'events' => [],
                'types' => [],
                'saved_months' => $this->months($savedAt),
                'content_months' => $this->months($events->pluck('event_date')),
            ],
        ]);
    }

    private function savedEvents(Request $request)
    {
        $userId = $request->user()->id;
        $canSeePrivate = $request->user()->can('view-private-media');

        return Event::query()
            ->when(! $canSeePrivate, fn ($q) => $q->where('events.visibility', 'public'))
            ->join('user_event_favorites', function ($join) use ($userId) {
                $join->on('user_event_favorites.event_id', '=', 'events.id')
                    ->where('user_event_favorites.user_id', $userId);
            })
            ->select('events.*');
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function eventIdsWithTag(Request $request, int $tagId)
    {
        return $this->visibleMedia($request)
            ->whereNotNull('event_id')
            ->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId))
            ->pluck('event_id');
    }

    private function visibleMedia(Request $request)
    {
        $canSeePrivate = $request->user()->can('view-private-media');

        return MediaItem::query()
            ->where('status', MediaItem::STATUS_APPROVED)
            ->when(! $canSeePrivate, function ($query) {
                $query->where(function ($q) {
                    $q->where('visibility_override', 'public')
                        ->orWhere(function ($inherit) {
                            $inherit->whereNull('visibility_override')
                                ->where(function ($event) {
                                    $event->whereDoesntHave('event', fn ($e) => $e->withTrashed())
                                        ->orWhereHas('event', fn ($e) => $e->withTrashed()->where('visibility', 'public'));
                                });
                        });
                });
            });
    }

    /**
     * @param  iterable<mixed>  $dates
     * @return array<int, string>
     */
    private function months(iterable $dates): array
    {
        return collect($dates)
            ->filter()
            ->map(function ($date) {
                if ($date instanceof \DateTimeInterface) {
                    return $date->format('Y-m');
                }

                $value = substr((string) $date, 0, 7);

                return preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? $value : null;
            })
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    private function presentPage(Request $request, $events)
    {
        $ids = $events->getCollection()->pluck('id');
        $favoriteIds = UserEventFavorite::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('event_id', $ids)
            ->pluck('event_id')
            ->flip();

        $events->getCollection()->transform(
            fn (Event $event) => $this->toArray($event, $favoriteIds->has($event->id))
        );

        return $events;
    }

    private function isFavorite(Request $request, Event $event): bool
    {
        return UserEventFavorite::query()
            ->where('user_id', $request->user()->id)
            ->where('event_id', $event->id)
            ->exists();
    }
}
