<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\S3MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function __construct(private readonly S3MediaStore $store) {}

    /**
     * Step 1: client asks for presigned PUT URLs for a batch of files.
     * Each file becomes a `media_items` row in `staging` status immediately,
     * so the review queue can show "uploading..." placeholders right away.
     */
    public function presign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'files' => ['required', 'array', 'min:1', 'max:100'],
            'files.*.filename' => ['required', 'string'],
            'files.*.content_type' => ['required', 'string'],
            'files.*.type' => ['required', 'in:image,video'],
            'files.*.size_bytes' => ['nullable', 'integer'],
        ]);

        $results = [];

        foreach ($data['files'] as $file) {
            $key = $this->store->newStagingKey($file['filename']);

            $mediaItem = MediaItem::query()->create([
                'event_id' => $data['event_id'] ?? null,
                'type' => $file['type'],
                's3_key' => $key,
                'status' => MediaItem::STATUS_STAGING,
                'size_bytes' => $file['size_bytes'] ?? null,
                'uploaded_by' => $request->user()->id,
            ]);

            $results[] = [
                'media_item_id' => $mediaItem->id,
                'upload_url' => $this->store->presignedPutUrl($key, $file['content_type']),
            ];
        }

        return response()->json(['data' => $results], 201);
    }

    /**
     * Step 2: client confirms the PUT succeeded for a given item. Flips it to
     * pending_processing so the media:process-pending cron tick picks it up
     * (images get thumbnail + BlurHash + Rekognition; videos need a
     * client-supplied poster-frame thumbnail passed here, since the server
     * has no ffmpeg on shared hosting).
     */
    public function complete(Request $request, MediaItem $mediaItem): JsonResponse
    {
        abort_if($mediaItem->uploaded_by !== $request->user()->id, 403);
        abort_if($mediaItem->status !== MediaItem::STATUS_STAGING, 422, 'Already processed.');

        $data = $request->validate([
            'width' => ['nullable', 'integer'],
            'height' => ['nullable', 'integer'],
            'duration_seconds' => ['nullable', 'integer'],
            // Base64 JPEG poster frame for videos (image uploads don't need this -
            // the server generates their thumbnail/BlurHash itself).
            'poster_frame_base64' => ['nullable', 'string'],
        ]);

        if ($mediaItem->type === 'video' && ! empty($data['poster_frame_base64'])) {
            $thumbKey = $this->store->thumbnailKeyFor($mediaItem->s3_key);
            $this->store->putObject(
                $thumbKey,
                base64_decode($data['poster_frame_base64']),
                'image/jpeg',
            );
            $mediaItem->thumbnail_key = $thumbKey;
        }

        $mediaItem->fill([
            'width' => $data['width'] ?? $mediaItem->width,
            'height' => $data['height'] ?? $mediaItem->height,
            'duration_seconds' => $data['duration_seconds'] ?? $mediaItem->duration_seconds,
            'status' => $mediaItem->type === 'video' && $mediaItem->thumbnail_key
                ? MediaItem::STATUS_PENDING_REVIEW
                : MediaItem::STATUS_PENDING_PROCESSING,
        ])->save();

        return response()->json(['data' => ['id' => $mediaItem->id, 'status' => $mediaItem->status]]);
    }
}
