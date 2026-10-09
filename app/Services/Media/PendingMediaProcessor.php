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
                $item->update([
                    'processing_attempts' => $attempts,
                    'status' => MediaItem::STATUS_PENDING_REVIEW,
                ]);

                return;
            }

            $item->update(['processing_attempts' => $attempts]);

            if ($item->isImage()) {
                $this->processImage($item);
            } else {
                // Videos never get server-side auto-tagging/thumbnailing -
                // the client already supplied a poster frame on complete().
                $item->update(['status' => MediaItem::STATUS_PENDING_REVIEW]);
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
        $binary = $this->store->getObjectBody($item->s3_key);

        $result = $this->thumbnails->process($binary);
        $thumbKey = $this->store->thumbnailKeyFor($item->s3_key);
        $this->store->putObject($thumbKey, $result['thumbnail'], 'image/jpeg');

        $item->fill([
            'thumbnail_key' => $thumbKey,
            'blurhash' => $result['blurhash'],
            'width' => $result['width'],
            'height' => $result['height'],
        ])->save();

        $autoTags = $this->rekognition->autoTag($item);

        foreach ($autoTags as $suggestion) {
            $item->tags()->syncWithoutDetaching([
                $suggestion['tag']->id => [
                    'source' => $suggestion['source'],
                    'confidence' => $suggestion['confidence'],
                ],
            ]);
        }

        $item->update(['status' => MediaItem::STATUS_PENDING_REVIEW]);
    }

    private function recordFailure(MediaItem $item): void
    {
        $item->refresh();

        if ($item->status !== MediaItem::STATUS_PENDING_PROCESSING) {
            return;
        }

        if ($item->processing_attempts >= self::MAX_ATTEMPTS) {
            $item->update(['status' => MediaItem::STATUS_PENDING_REVIEW]);
        }
    }
}
