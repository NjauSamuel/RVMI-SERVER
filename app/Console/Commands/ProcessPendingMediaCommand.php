<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Media\RekognitionAutoTagger;
use App\Services\Media\S3MediaStore;
use App\Services\Media\ThumbnailGenerator;
use Illuminate\Console\Command;

/**
 * Runs every minute via cron (`php artisan schedule:run`). Picks up a small
 * batch of images that finished uploading to S3, auto-tags them with
 * Rekognition, generates a thumbnail + BlurHash, and moves them to
 * pending_review. Kept deliberately small/batched so it finishes well
 * inside a shared-hosting cron execution budget - there is no persistent
 * queue worker to fall back on.
 */
class ProcessPendingMediaCommand extends Command
{
    protected $signature = 'media:process-pending';

    protected $description = 'Auto-tag + thumbnail a small batch of newly uploaded images.';

    public function __construct(
        private readonly S3MediaStore $store,
        private readonly RekognitionAutoTagger $rekognition,
        private readonly ThumbnailGenerator $thumbnails,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchSize = (int) config('services.media_pipeline.process_batch_size', 8);

        $items = MediaItem::query()
            ->where('status', MediaItem::STATUS_PENDING_PROCESSING)
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        foreach ($items as $item) {
            $this->processOne($item);
        }

        $this->info("Processed {$items->count()} media item(s).");

        return self::SUCCESS;
    }

    private function processOne(MediaItem $item): void
    {
        try {
            if ($item->isImage()) {
                $this->processImage($item);
            } else {
                // Videos never get server-side auto-tagging/thumbnailing -
                // the client already supplied a poster frame on complete().
                $item->update(['status' => MediaItem::STATUS_PENDING_REVIEW]);
            }
        } catch (\Throwable $e) {
            report($e);
            // Leave it in pending_processing; it will be retried next tick.
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
}
