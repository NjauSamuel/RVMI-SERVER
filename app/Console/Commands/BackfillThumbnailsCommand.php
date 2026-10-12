<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Media\PendingMediaProcessor;
use Illuminate\Console\Command;

/**
 * Makes the missing 480px preview for photos already approved.
 * One small batch per run so shared hosting is not asked to decode
 * the whole library in a single process. Status is left approved.
 */
class BackfillThumbnailsCommand extends Command
{
    protected $signature = 'media:backfill-thumbnails {--limit=8}';

    protected $description = 'Create thumbnails for approved photos that do not have one yet.';

    public function __construct(private readonly PendingMediaProcessor $processor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $items = MediaItem::query()
            ->where('status', MediaItem::STATUS_APPROVED)
            ->where('type', 'image')
            ->whereNull('thumbnail_key')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($items as $item) {
            $this->processor->backfillThumbnail($item);
        }

        $this->info("Backfilled {$items->count()} thumbnail(s).");

        return self::SUCCESS;
    }
}
