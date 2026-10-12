<?php

namespace App\Console\Commands;

use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes scene words Rekognition DetectLabels used to attach (Face, Head,
 * Glasses, and the rest). Person-name tags stay. A moment tag a reviewer
 * attached by hand stays, including on photos where it was also auto-attached.
 */
class PurgeDetectedLabelsCommand extends Command
{
    protected $signature = 'tags:purge-detected-labels {--dry-run : Show what would be removed without deleting}';

    protected $description = 'Remove auto-detected scene labels while keeping person names and hand-attached tags.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $momentIds = Tag::query()
            ->where('kind', 'moment')
            ->whereNull('person_id')
            ->pluck('id');

        if ($momentIds->isEmpty()) {
            $this->info('No scene-label tags to remove.');

            return self::SUCCESS;
        }

        $autoPivots = DB::table('media_tag')
            ->where('source', 'auto')
            ->whereIn('tag_id', $momentIds);

        $manualTagIds = DB::table('media_tag')
            ->where('source', 'manual')
            ->whereIn('tag_id', $momentIds)
            ->distinct()
            ->pluck('tag_id');

        $deletable = Tag::query()->whereIn('id', $momentIds);
        if ($manualTagIds->isNotEmpty()) {
            $deletable->whereNotIn('id', $manualTagIds);
        }

        $detachCount = (clone $autoPivots)->count();
        $deleteCount = (clone $deletable)->count();
        $names = (clone $deletable)->orderBy('name')->pluck('name');

        $this->line("Auto scene-label links: {$detachCount}");
        $this->line("Scene-label tags to delete: {$deleteCount}");
        if ($manualTagIds->isNotEmpty()) {
            $kept = Tag::query()->whereIn('id', $manualTagIds)->orderBy('name')->pluck('name');
            $this->line('Kept because a reviewer attached them: '.$kept->join(', '));
        }
        if ($names->isNotEmpty()) {
            $this->line($names->join(', '));
        }

        if ($dryRun) {
            $this->comment('Dry run. Nothing was deleted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($autoPivots, $deletable) {
            $autoPivots->delete();
            $deletable->delete();
        });

        $this->info('Removed auto-detected scene labels.');

        return self::SUCCESS;
    }
}
