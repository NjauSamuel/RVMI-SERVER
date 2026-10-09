<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Media\PendingMediaProcessor;
use Illuminate\Console\Command;

/**
 * Safety net for uploads whose after-response processing was killed.
 * Normal images are handled inside UploadController::complete(). This
 * command only retries rows still in pending_processing, in a small batch,
 * on the shared-hosting cron (every five minutes — Namecheap's minimum).
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
            ->where('status', MediaItem::STATUS_PENDING_PROCESSING)
            ->where('updated_at', '<=', now()->subMinutes(self::SWEEP_AFTER_MINUTES))
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        foreach ($items as $item) {
            $this->processor->process($item);
        }

        $this->info("Processed {$items->count()} media item(s).");

        return self::SUCCESS;
    }
}
