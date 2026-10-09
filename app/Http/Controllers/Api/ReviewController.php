<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Tag;
use App\Services\Media\CloudFrontUrlSigner;
use App\Services\Media\S3MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    public function __construct(
        private readonly S3MediaStore $store,
        private readonly CloudFrontUrlSigner $signer,
    ) {}

    /**
     * Everything awaiting human review, grouped loosely by upload batch
     * (same uploader + same event, most recent first) for the "Media Grid +
     * Sticky Tag Panel" bulk-tagging UI.
     */
    public function pending(Request $request): JsonResponse
    {
        $items = MediaItem::query()
            ->where('status', MediaItem::STATUS_PENDING_REVIEW)
            ->with(['tags', 'event:id,title', 'uploader:id,name'])
            ->orderBy('created_at')
            ->paginate(60);

        $items->getCollection()->transform(fn (MediaItem $item) => $this->toArray($item));

        return response()->json($items);
    }

    public function update(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'visibility_override' => ['nullable', 'in:public,private'],
            'add_tag_names' => ['array'],
            'add_tag_names.*' => ['string', 'max:100'],
            'remove_tag_ids' => ['array'],
            'remove_tag_ids.*' => ['integer'],
        ]);

        if (array_key_exists('event_id', $data)) {
            $mediaItem->event_id = $data['event_id'];
        }
        if (array_key_exists('visibility_override', $data)) {
            $mediaItem->visibility_override = $data['visibility_override'];
        }
        $mediaItem->save();

        foreach ($data['remove_tag_ids'] ?? [] as $tagId) {
            $mediaItem->tags()->detach($tagId);
        }

        foreach ($data['add_tag_names'] ?? [] as $name) {
            $tag = Tag::query()->firstOrCreate(['name' => $name, 'kind' => 'moment']);
            $mediaItem->tags()->syncWithoutDetaching([
                $tag->id => ['source' => 'manual'],
            ]);
        }

        return response()->json(['data' => $this->toArray($mediaItem->fresh(['tags', 'event']))]);
    }

    public function approve(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->approveOne($mediaItem, $request->user()->id);

        return response()->json(['data' => $this->toArray($mediaItem->fresh(['tags', 'event']))]);
    }

    public function reject(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $mediaItem->update([
            'status' => MediaItem::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $this->store->deleteObject($mediaItem->s3_key);

        return response()->json(['message' => 'Rejected.']);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'media_item_ids' => ['required', 'array', 'min:1'],
            'media_item_ids.*' => ['integer', 'exists:media_items,id'],
        ]);

        $items = MediaItem::query()->whereIn('id', $data['media_item_ids'])->get();

        foreach ($items as $item) {
            $this->approveOne($item, $request->user()->id);
        }

        return response()->json(['message' => count($items).' item(s) approved.']);
    }

    private function approveOne(MediaItem $mediaItem, int $reviewerId): void
    {
        DB::transaction(function () use ($mediaItem, $reviewerId) {
            $finalKey = $this->store->finalMediaKey($mediaItem->event_id, $mediaItem->s3_key);
            $this->store->copyObject($mediaItem->s3_key, $finalKey);

            $finalThumbKey = null;
            if ($mediaItem->thumbnail_key) {
                $finalThumbKey = $this->store->thumbnailKeyFor($finalKey);
                $this->store->copyObject($mediaItem->thumbnail_key, $finalThumbKey);
                $this->store->deleteObject($mediaItem->thumbnail_key);
            }

            $this->store->deleteObject($mediaItem->s3_key);

            $mediaItem->update([
                's3_key' => $finalKey,
                'thumbnail_key' => $finalThumbKey,
                'status' => MediaItem::STATUS_APPROVED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function toArray(MediaItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'status' => $item->status,
            'event' => $item->event ? ['id' => $item->event->id, 'title' => $item->event->title] : null,
            'visibility_override' => $item->visibility_override,
            'thumbnail_url' => $item->thumbnail_key ? $this->signer->signedUrl($item->thumbnail_key) : null,
            'full_url' => $this->signer->signedUrl($item->s3_key),
            'blurhash' => $item->blurhash,
            'width' => $item->width,
            'height' => $item->height,
            'uploader' => $item->uploader?->name,
            'tags' => $item->tags->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'kind' => $tag->kind,
                'source' => $tag->pivot->source,
                'confidence' => $tag->pivot->confidence,
            ]),
            'created_at' => $item->created_at,
        ];
    }
}
