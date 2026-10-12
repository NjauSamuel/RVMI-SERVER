<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Cache;

/**
 * Thumbnail, BlurHash, and Rekognition for one image that just finished
 * uploading. Called after the upload response is sent, and again by the
 * 5-minute sweeper if that work died. One item per call — shared hosting
 * cannot chew through a large batch in a single PHP process.
 */
class PendingMediaProcessor
{
    private const MAX_ATTEMPTS = 3;

    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly S3MediaStore $store,
        private readonly RekognitionAutoTagger $rekognition,
        private readonly ThumbnailGenerator $thumbnails,
    ) {}

    public function process(MediaItem $item): void
    {
        $lock = Cache::lock('media-process-'.$item->id, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $item->refresh();

            if ($item->status !== MediaItem::STATUS_PENDING_PROCESSING) {
                return;
            }

            // Count the try before the slow work so a killed PHP process
            // still advances toward give-up. A photo that always times out
            // must not sit at the head of the sweeper forever.
            $attempts = $item->processing_attempts + 1;

            if ($attempts > self::MAX_ATTEMPTS) {
                $this->markPendingReview($item, ['processing_attempts' => $attempts]);

                return;
            }

            $item->update(['processing_attempts' => $attempts]);

            if ($item->isImage()) {
                $this->processImage($item);
            } else {
                // Videos never get server-side auto-tagging/thumbnailing -
                // the client already supplied a poster frame on complete().
                $this->markPendingReview($item);
            }
        } catch (\Throwable $e) {
            report($e);
            $this->recordFailure($item);
        } finally {
            $lock->release();
        }
    }

    private function processImage(MediaItem $item): void
    {
        if ($item->thumbnail_key) {
            $this->tagAndFinish($item);

            return;
        }

        $this->storeThumbnail($item);
        $this->tagAndFinish($item);
    }

    /**
     * Writes the small JPEG for a photo that is still in processing or review.
     * An approved or rejected row is left unchanged.
     */
    public function backfillThumbnail(MediaItem $item): void
    {
        $lock = Cache::lock('media-process-'.$item->id, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $item->refresh();

            if (! $this->canStoreThumbnail($item)) {
                return;
            }

            $this->storeThumbnail($item);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }
    }

    private function canStoreThumbnail(MediaItem $item): bool
    {
        return $item->isImage()
            && $item->thumbnail_key === null
            && in_array($item->status, [
                MediaItem::STATUS_PENDING_PROCESSING,
                MediaItem::STATUS_PENDING_REVIEW,
            ], true);
    }

    private function storeThumbnail(MediaItem $item): void
    {
        $binary = $this->store->getObjectBody($item->s3_key);
        $result = $this->thumbnails->process($binary);
        $thumbKey = $this->store->thumbnailKeyFor($item->s3_key);
        $this->store->putObject($thumbKey, $result['thumbnail'], 'image/jpeg');

        $item->refresh();
        if (! $this->canStoreThumbnail($item)) {
            return;
        }

        $item->fill([
            'thumbnail_key' => $thumbKey,
            'blurhash' => $result['blurhash'] !== '' ? $result['blurhash'] : null,
            'width' => $result['width'],
            'height' => $result['height'],
        ])->save();
    }

    /**
     * Rekognition reads the stored object directly. This does not download it.
     */
    private function tagAndFinish(MediaItem $item): void
    {
        $item->refresh();
        if ($item->status !== MediaItem::STATUS_PENDING_PROCESSING) {
            return;
        }

        $autoTags = $this->rekognition->autoTag($item);

        foreach ($autoTags as $suggestion) {
            $item->tags()->syncWithoutDetaching([
                $suggestion['tag']->id => [
                    'source' => $suggestion['source'],
                    'confidence' => $suggestion['confidence'],
                ],
            ]);
        }

        $this->markPendingReview($item);
    }

    /**
     * An approver may have already accepted the upload while this slow
     * work was running. Never move an approved or rejected row backwards.
     *
     * @param  array<string, mixed>  $extra
     */
    private function markPendingReview(MediaItem $item, array $extra = []): void
    {
        $item->refresh();

        if ($item->status !== MediaItem::STATUS_PENDING_PROCESSING) {
            return;
        }

        $item->update(['status' => MediaItem::STATUS_PENDING_REVIEW, ...$extra]);
    }

    private function recordFailure(MediaItem $item): void
    {
        $item->refresh();

        if ($item->status !== MediaItem::STATUS_PENDING_PROCESSING) {
            return;
        }

        if ($item->processing_attempts >= self::MAX_ATTEMPTS) {
            $this->markPendingReview($item);
        }
    }
}
