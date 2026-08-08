<?php

namespace App\Console\Commands;

use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Render every badge for an event to a print-ready PDF in one pass.
 *
 * Rendering used to be welded to printing, so artwork was produced one card at
 * a time while staff waited at the printer. Doing the whole event up front means
 * the print run is nothing but moving already-finished files to a printer.
 */
class GenerateBadgePrintFilesCommand extends Command
{
    protected $signature = 'badges:generate-print-files
        {--event= : Event id, defaults to the most recent event}
        {--force : Re-render even when the artwork inputs are unchanged}
        {--queue : Dispatch to the queue instead of rendering inline}
        {--limit= : Only process this many badges, useful for a trial run}';

    protected $description = 'Generate print-ready PDFs for an event\'s badges';

    public function handle(): int
    {
        $event = $this->resolveEvent();

        if (! $event) {
            $this->error('No event found.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $query = Badge::query()
            ->whereHas('fursuit', fn ($q) => $q->where('event_id', $event->id))
            ->with(['fursuit.species', 'fursuit.event']);

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $badges = $query->get();

        if ($badges->isEmpty()) {
            $this->info("No badges found for event {$event->id}.");

            return self::SUCCESS;
        }

        // Skipping unchanged badges is what makes this safe to re-run mid-event.
        $pending = $force
            ? $badges
            : $badges->filter(fn (Badge $badge) => $badge->print_file_hash !== GenerateBadgePrintFileJob::inputHash($badge)
                || ! $badge->print_file_path);

        $this->info("Event {$event->id}: {$badges->count()} badges, {$pending->count()} need rendering.");

        if ($pending->isEmpty()) {
            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            Bus::batch($pending->map(fn (Badge $badge) => new GenerateBadgePrintFileJob($badge, $force))->all())
                ->name("Badge print files for event {$event->id}")
                ->allowFailures()
                ->dispatch();

            $this->info("Dispatched {$pending->count()} render jobs to the queue.");

            return self::SUCCESS;
        }

        $failures = 0;
        $bar = $this->output->createProgressBar($pending->count());
        $bar->start();

        foreach ($pending as $badge) {
            try {
                (new GenerateBadgePrintFileJob($badge, $force))->handle();
            } catch (\Throwable $e) {
                $failures++;
                $this->newLine();
                $this->warn("Badge {$badge->id} ({$badge->custom_id}) failed: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $rendered = $pending->count() - $failures;
        $this->info("Rendered {$rendered} badges, {$failures} failed.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveEvent(): ?Event
    {
        if ($eventId = $this->option('event')) {
            return Event::find($eventId);
        }

        return Event::query()->latest('id')->first();
    }
}
