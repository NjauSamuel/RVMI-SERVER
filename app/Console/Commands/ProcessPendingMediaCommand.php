<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Media\PendingMediaProcessor;
use Illuminate\Console\Command;

/**
 * Safety net for uploads whose after-response processing was killed.
 * Normal images are handled inside UploadController::complete(). This
 * command retries a small batch on the shared-hosting cron (every five
 * minutes — Namecheap's minimum). That batch is items still processing,
 * plus review photos that never received a thumbnail.
 */
class ProcessPendingMediaCommand extends Command
{
    private const SWEEP_AFTER_MINUTES = 2;

    protected $signature = 'media:process-pending';

    protected $description = 'Retry thumbnail + auto-tag for uploads that did not finish processing.';

    public function __construct(private readonly PendingMediaProcessor $processor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchSize = (int) config('services.media_pipeline.process_batch_size', 8);

        $items = MediaItem::query()
            ->where('updated_at', '<=', now()->subMinutes(self::SWEEP_AFTER_MINUTES))
            ->where(function ($query) {
                $query->where('status', MediaItem::STATUS_PENDING_PROCESSING)
                    ->orWhere(function ($query) {
                        $query->where('status', MediaItem::STATUS_PENDING_REVIEW)
                            ->where('type', 'image')
                            ->whereNull('thumbnail_key');
                    });
            })
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        foreach ($items as $item) {
            if ($item->status === MediaItem::STATUS_PENDING_PROCESSING) {
                $this->processor->process($item);
            } else {
                $this->processor->backfillThumbnail($item);
            }
        }

        $this->info("Processed {$items->count()} media item(s).");

        return self::SUCCESS;
    }
}
