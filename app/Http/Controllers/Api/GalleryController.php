<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Models\Tag;
use App\Models\UserMediaFavorite;
use App\Services\Media\CloudFrontUrlSigner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

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
        $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'type' => ['sometimes', 'nullable', 'in:image,video'],
        ]);

        $items = $this->visibleItems($request)
            ->when($request->integer('event_id'), fn ($q, $eventId) => $q->where('event_id', $eventId))
            ->when($request->integer('tag_id'), function ($q, $tagId) {
                $q->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId));
            })
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->with('tags')
            ->orderByDesc('created_at')
            ->paginate(40);

        return response()->json($this->presentPage($request, $items));
    }

    public function saved(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $items = $this->visibleItems($request)
            ->join('user_media_favorites', function ($join) use ($userId) {
                $join->on('user_media_favorites.media_item_id', '=', 'media_items.id')
                    ->where('user_media_favorites.user_id', $userId);
            })
            ->select('media_items.*')
            ->with('tags')
            ->orderByDesc('user_media_favorites.created_at')
            ->paginate(40);

        return response()->json($this->presentPage($request, $items));
    }

    public function show(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->assertVisible($request, $mediaItem);

        return response()->json(['data' => $this->toArray($mediaItem->load('tags'), $this->isFavorite($request, $mediaItem))]);
    }

    public function favorite(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->assertVisible($request, $mediaItem);

        UserMediaFavorite::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'media_item_id' => $mediaItem->id,
        ]);

        return response()->json(['message' => 'Saved.']);
    }

    public function unfavorite(Request $request, MediaItem $mediaItem): JsonResponse
    {
        UserMediaFavorite::query()
            ->where('user_id', $request->user()->id)
            ->where('media_item_id', $mediaItem->id)
            ->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /**
     * Record that the user looked at this item's tags, powering the
     * favorites-free "suggested for you" heuristic.
     */
    public function recordView(Request $request, MediaItem $mediaItem): JsonResponse
    {
        foreach ($mediaItem->tags as $tag) {
            $view = \App\Models\UserTagView::query()->firstOrNew([
                'user_id' => $request->user()->id,
                'tag_id' => $tag->id,
            ]);
            $view->view_count = ($view->view_count ?? 0) + 1;
            $view->last_viewed_at = now();
            $view->save();
        }

        return response()->json(['message' => 'ok']);
    }

    public function destroy(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $kind = $mediaItem->type === 'video' ? 'video' : 'photo';
        $mediaItem->delete();

        AuditLog::record(
            $request->user(),
            'media.deleted',
            $mediaItem,
            "Deleted {$kind} #{$mediaItem->id}.",
        );

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * @return Builder<MediaItem>
     */
    private function visibleItems(Request $request): Builder
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

    private function assertVisible(Request $request, MediaItem $mediaItem): void
    {
        abort_if($mediaItem->status !== MediaItem::STATUS_APPROVED, 404);

        if ($mediaItem->effectiveVisibility() === 'private') {
            abort_if(! $request->user()->can('view-private-media'), 403);
        }
    }

    private function isFavorite(Request $request, MediaItem $mediaItem): bool
    {
        return UserMediaFavorite::query()
            ->where('user_id', $request->user()->id)
            ->where('media_item_id', $mediaItem->id)
            ->exists();
    }

    /**
     * @param  LengthAwarePaginator<int, MediaItem>  $items
     */
    private function presentPage(Request $request, LengthAwarePaginator $items): LengthAwarePaginator
    {
        $ids = $items->getCollection()->pluck('id');
        $favoriteIds = UserMediaFavorite::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('media_item_id', $ids)
            ->pluck('media_item_id')
            ->flip();

        $items->getCollection()->transform(
            fn (MediaItem $item) => $this->toArray($item, $favoriteIds->has($item->id))
        );

        return $items;
    }

    /** @return array<string, mixed> */
    private function toArray(MediaItem $item, bool $isFavorite = false): array
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
            'is_favorite' => $isFavorite,
            'tags' => $item->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'kind' => $tag->kind]),
            'created_at' => $item->created_at,
        ];
    }
}
