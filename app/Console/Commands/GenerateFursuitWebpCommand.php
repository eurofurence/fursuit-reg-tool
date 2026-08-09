<?php

namespace App\Console\Commands;

use App\Jobs\GenerateFursuitWebpJob;
use App\Models\Fursuit\Fursuit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill the gallery webp variants that the accessor used to render on page load.
 *
 * `--stale` also catches rows whose variants no longer match the current photo, or whose
 * files are missing from the bucket entirely - the shape a database copy taken from a
 * different environment leaves behind, and the one the old on-read generation left too.
 */
class GenerateFursuitWebpCommand extends Command
{
    protected $signature = 'fursuits:generate-webp
        {--all : Re-render every fursuit, even those with an up to date webp}
        {--stale : Also re-render rows whose variants are missing from storage or no longer match the photo}
        {--forget-missing : First null the variant columns whose files are not in this bucket (implies --stale)}
        {--forget-all : First null every variant column, so nothing claims a variant it cannot prove}
        {--sync : Run the encodes in this process instead of queueing them}
        {--dry-run : Only report how many fursuits would be rendered}
        {--limit=0 : Stop after this many fursuits (0 = no limit)}';

    protected $description = 'Render missing (or stale) gallery WebP variants for fursuit photos';

    /**
     * Every derived file that exists on the disk, keyed for lookup.
     *
     * `--stale` has to know whether each variant is actually on the bucket. Asking per
     * row is two round trips per fursuit - on the order of 40k requests for a full
     * convention's worth of photos, which is what made this command sit silent for
     * minutes. One listing per prefix answers all of them.
     *
     * @var array<string, true>|null
     */
    private ?array $existingFiles = null;

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $queued = 0;
        $skipped = 0;

        $query = Fursuit::query()->withTrashed()->whereNotNull('image');
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No fursuits with a photo.');

            return self::SUCCESS;
        }

        if ($this->needsListing()) {
            $this->line('Listing rendered variants on '.config('filesystems.default').'...');
            $this->loadExistingFiles();
            $this->line(count($this->existingFiles).' variants already on disk.');
        }

        $this->forgetVariants($query);

        $verb = match (true) {
            (bool) $this->option('dry-run') => 'Checking',
            (bool) $this->option('sync') => 'Rendering',
            default => 'Queueing',
        };
        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->setFormat(" {$verb} %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%\n %message%");
        $bar->setMessage('starting');
        $bar->start();

        $query->orderBy('id')
            ->chunkById(200, function ($fursuits) use (&$queued, &$skipped, $limit, $bar) {
                foreach ($fursuits as $fursuit) {
                    if (! $this->needsRender($fursuit)) {
                        $skipped++;

                        // Only rendered rows count towards a --limit run, so the bar
                        // tracks those; skipped rows just move the message along.
                        if ($limit === 0) {
                            $bar->advance();
                        }

                        continue;
                    }

                    $bar->setMessage('fursuit '.$fursuit->id);

                    if (! $this->option('dry-run')) {
                        $job = new GenerateFursuitWebpJob($fursuit->id);

                        if ($this->option('sync')) {
                            $job->handle();
                        } else {
                            dispatch($job);
                        }
                    }

                    $queued++;

                    if ($limit > 0) {
                        $bar->advance();
                    }

                    if ($limit > 0 && $queued >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        $bar->finish();
        $this->newLine(2);

        $done = match (true) {
            (bool) $this->option('dry-run') => 'would be rendered',
            (bool) $this->option('sync') => 'rendered',
            default => 'queued',
        };
        $this->info("{$queued} fursuits {$done}, {$skipped} already current.");

        if ($queued > 0 && ! $this->option('sync') && ! $this->option('dry-run')) {
            $this->line('Run a queue worker to process them: php artisan queue:work');
        }

        return self::SUCCESS;
    }

    private function needsListing(): bool
    {
        return (bool) ($this->option('stale') || $this->option('forget-missing'));
    }

    /**
     * Null the variant columns before rendering anything.
     *
     * A database restored from another environment carries paths that were written where
     * the objects were - so the column claims a variant this bucket has never held, the
     * signed URL 404s, and the card renders as a broken image. A null column is honest:
     * the accessor falls back to the master photo until the render lands.
     *
     * `--forget-missing` only clears what the bucket cannot back up, so the variants that
     * are genuinely there keep serving. `--forget-all` clears the lot.
     */
    private function forgetVariants(Builder $query): void
    {
        if (! $this->option('forget-all') && ! $this->option('forget-missing')) {
            return;
        }

        if ($this->option('dry-run')) {
            $this->line('Skipping the column reset: --dry-run.');

            return;
        }

        if ($this->option('forget-all')) {
            $cleared = (clone $query)->toBase()->update(['image_webp' => null, 'image_thumb' => null]);
            $this->line("Cleared the variant columns of {$cleared} fursuits.");

            return;
        }

        $ids = [];

        (clone $query)->select('id', 'image_webp', 'image_thumb')
            ->chunkById(2000, function ($rows) use (&$ids) {
                foreach ($rows as $row) {
                    $claimed = array_filter([$row->image_webp, $row->image_thumb]);

                    foreach ($claimed as $path) {
                        if (! $this->exists($path)) {
                            $ids[] = $row->id;
                            break;
                        }
                    }
                }
            });

        foreach (array_chunk($ids, 1000) as $chunk) {
            Fursuit::withTrashed()->whereIn('id', $chunk)
                ->toBase()->update(['image_webp' => null, 'image_thumb' => null]);
        }

        $this->line(count($ids).' fursuits pointed at variants this bucket does not hold; columns cleared.');
    }

    /**
     * One listing per prefix, so the per-row check is an array lookup.
     */
    private function loadExistingFiles(): void
    {
        $disk = Storage::disk();
        $paths = [
            ...$disk->files(dirname(GenerateFursuitWebpJob::pathFor('x.jpg'))),
            ...$disk->files(dirname(GenerateFursuitWebpJob::thumbPathFor('x.jpg'))),
        ];

        $this->existingFiles = array_fill_keys($paths, true);
    }

    private function exists(string $path): bool
    {
        return isset($this->existingFiles[$path]);
    }

    private function needsRender(Fursuit $fursuit): bool
    {
        if ($this->option('all')) {
            return true;
        }

        if (! $fursuit->image_webp || ! $fursuit->image_thumb) {
            return true;
        }

        if (! $this->option('stale')) {
            return false;
        }

        // The variant paths are derived from the original's filename, so a mismatch means
        // the photo was replaced without the variants following it.
        return $fursuit->image_webp !== GenerateFursuitWebpJob::pathFor($fursuit->image)
            || $fursuit->image_thumb !== GenerateFursuitWebpJob::thumbPathFor($fursuit->image)
            || ! $this->exists($fursuit->image_webp)
            || ! $this->exists($fursuit->image_thumb);
    }
}
