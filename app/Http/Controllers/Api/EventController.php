<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
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

        $events->getCollection()->transform(fn (Event $event) => $this->toArray($event));

        return response()->json($events);
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

        return response()->json(['data' => $this->toArray($event)], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $event->assertVisibleTo($request->user());

        return response()->json(['data' => $this->toArray($event)]);
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

    /** @return array<string, mixed> */
    private function toArray(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'event_date' => $event->event_date?->toDateString(),
            'visibility' => $event->visibility,
            'media_count' => $event->media_count ?? $event->approvedMedia()->count(),
            'cover_thumbnail_url' => $event->coverMedia?->thumbnail_key
                ? $this->signer->signedUrl($event->coverMedia->thumbnail_key)
                : null,
            'cover_blurhash' => $event->coverMedia?->blurhash,
        ];
    }
}
