<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Tag;
use App\Models\UserTagView;
use App\Services\Media\CloudFrontUrlSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GalleryController extends Controller
{
    public function __construct(private readonly CloudFrontUrlSigner $signer) {}

    /**
     * Main paginated timeline, newest first. Scoped to approved items the
     * current user is allowed to see, and optionally filtered to a single
     * tag (person/moment/category) or event.
     */
    public function index(Request $request): JsonResponse
    {
        $canSeePrivate = $request->user()->can('view-private-media');

        $items = MediaItem::query()
            ->where('status', MediaItem::STATUS_APPROVED)
            ->when(! $canSeePrivate, function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('visibility_override')
                        ->whereHas('event', fn ($e) => $e->where('visibility', 'public'));
                })->orWhere('visibility_override', 'public');
            })
            ->when($request->integer('event_id'), fn ($q, $eventId) => $q->where('event_id', $eventId))
            ->when($request->integer('tag_id'), function ($q, $tagId) {
                $q->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId));
            })
            ->with('tags')
            ->orderByDesc('created_at')
            ->paginate(40);

        $items->getCollection()->transform(fn (MediaItem $item) => $this->toArray($item));

        return response()->json($items);
    }

    public function show(Request $request, MediaItem $mediaItem): JsonResponse
    {
        abort_if($mediaItem->status !== MediaItem::STATUS_APPROVED, 404);

        if ($mediaItem->effectiveVisibility() === 'private') {
            abort_if(! $request->user()->can('view-private-media'), 403);
        }

        return response()->json(['data' => $this->toArray($mediaItem->load('tags'))]);
    }

    /**
     * Record that the user looked at this item's tags, powering the
     * favorites-free "suggested for you" heuristic.
     */
    public function recordView(Request $request, MediaItem $mediaItem): JsonResponse
    {
        foreach ($mediaItem->tags as $tag) {
            $view = UserTagView::query()->firstOrNew([
                'user_id' => $request->user()->id,
                'tag_id' => $tag->id,
            ]);
            $view->view_count = ($view->view_count ?? 0) + 1;
            $view->last_viewed_at = now();
            $view->save();
        }

        return response()->json(['message' => 'ok']);
    }

    /** @return array<string, mixed> */
    private function toArray(MediaItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'event_id' => $item->event_id,
            'thumbnail_url' => $item->thumbnail_key ? $this->signer->signedUrl($item->thumbnail_key) : null,
            'full_url' => $this->signer->signedUrl($item->s3_key),
            'blurhash' => $item->blurhash,
            'width' => $item->width,
            'height' => $item->height,
            'duration_seconds' => $item->duration_seconds,
            'tags' => $item->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'kind' => $tag->kind]),
            'created_at' => $item->created_at,
        ];
    }
}
