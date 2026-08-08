<?php

namespace App\Jobs\Printing;

use App\Badges\EF28_Badge;
use App\Badges\EF29_Badge;
use App\Badges\EF30_Badge;
use App\Models\Badge\Badge;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Render a badge to a print-ready PDF and record it on the badge.
 *
 * Rendering used to happen inside PrintBadgeJob, so it could only ever run as a
 * side effect of queueing something to a printer: nothing could be prepared in
 * advance and every reprint re-rendered from scratch. Splitting it out means an
 * entire event's artwork can be generated in one pass, well before anyone is
 * standing at a printer waiting for it.
 *
 * Idempotent. The hash covers everything that affects the artwork, so a repeat
 * run over an event costs a query per badge and nothing else.
 */
class GenerateBadgePrintFileJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    public $tries = 3;

    /**
     * `$force` re-renders artwork whose inputs have not moved. `$ignorePrintingLock` is a
     * different question and deliberately a separate flag: it renders a badge that is
     * committed to a run. Only a caller that has established the badge has no card queued
     * may pass it, which today is BadgePrintQueue on a reprint. A blanket `--force` pass
     * must never carry it, or it would overwrite the PDF under a card waiting to print.
     */
    public function __construct(
        public readonly Badge $badge,
        public readonly bool $force = false,
        public readonly bool $ignorePrintingLock = false,
    ) {
        $this->onQueue('badge-render');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $badge = $this->badge->fresh(['fursuit.species', 'fursuit.event']);

        if (! $badge) {
            return;
        }

        // A badge committed to a batch is frozen. Re-rendering it now would
        // change the artwork under a card that is queued to print, or has
        // already printed, and the stack would stop matching the orders.
        //
        // A reprint is the one exception, and it has to be: the lock outlives
        // the run that set it, so without this a badge whose artwork inputs
        // moved after it printed could never be re-rendered, and build()
        // refuses the stale file it still carries. The caller says so
        // explicitly and only after establishing that no card is queued.
        if ($badge->isPrintingLocked() && ! $this->ignorePrintingLock) {
            Log::info('Skipped print file regeneration for a locked badge', [
                'badge_id' => $badge->id,
                'locked_at' => $badge->printing_locked_at?->toIso8601String(),
            ]);

            return;
        }

        $rendererClass = self::rendererFor($badge);
        $hash = self::inputHash($badge, $rendererClass);

        if (! $this->force && $badge->print_file_hash === $hash && $badge->print_file_path) {
            if (Storage::exists($badge->print_file_path)) {
                return;
            }
        }

        $renderer = new $rendererClass;
        $pdf = $renderer->getPdf($badge);

        $path = 'badges/'.$badge->id.'.pdf';
        Storage::put($path, $pdf);

        $badge->forceFill([
            'print_file_path' => $path,
            'print_file_hash' => $hash,
            'print_file_renderer' => class_basename($rendererClass),
            'print_file_generated_at' => now(),
        ])->saveQuietly();

        Log::info('Badge print file generated', [
            'badge_id' => $badge->id,
            'custom_id' => $badge->custom_id,
            'renderer' => class_basename($rendererClass),
            'path' => $path,
        ]);
    }

    /**
     * Mark a badge's rendered file stale.
     *
     * Called whenever something that appears on the card changes, so an edited
     * badge can never be batched with artwork that no longer matches the order.
     *
     * This clears the fingerprint rather than rendering. Rendering a PDF is slow
     * and belongs nowhere near the request in which somebody saved a form, and
     * an attendee fiddling with their badge five times in a row should not queue
     * five renders. The next `badges:generate-print-files` pass picks it up, and
     * that pass is what a batch is built from.
     *
     * A badge already committed to a batch is left alone: the order is frozen at
     * that point and the attendee cannot edit it anyway.
     */
    public static function invalidateFor(Badge $badge): void
    {
        if ($badge->isPrintingLocked()) {
            return;
        }

        $badge->forceFill([
            'print_file_hash' => null,
            'print_file_generated_at' => null,
        ])->saveQuietly();
    }

    /**
     * Pick the renderer the badge's event asks for.
     */
    public static function rendererFor(Badge $badge): string
    {
        // Default matches the current event's badge class. Getting this wrong is
        // silent: the card renders happily with the previous year's artwork.
        // Null-safe because inputHash() is now called on badges picked out of an
        // admin table, where a soft-deleted fursuit must not fatal the request.
        return match ($badge->fursuit?->event?->badge_class ?? 'EF30_Badge') {
            'EF30_Badge' => EF30_Badge::class,
            'EF29_Badge' => EF29_Badge::class,
            'EF28_Badge' => EF28_Badge::class,
            default => EF30_Badge::class,
        };
    }

    /**
     * Fingerprint every input that changes what lands on the card, so a
     * regeneration pass can skip badges whose artwork cannot have moved.
     */
    public static function inputHash(Badge $badge, ?string $rendererClass = null): string
    {
        $fursuit = $badge->fursuit;

        return hash('sha256', json_encode([
            'renderer' => $rendererClass ?? self::rendererFor($badge),
            'custom_id' => $badge->custom_id,
            'dual_side_print' => (bool) $badge->dual_side_print,
            'fursuit_name' => $fursuit?->name,
            'species' => $fursuit?->species?->name,
            'catch_code' => $fursuit?->catch_code,
            'catch_em_all' => (bool) $fursuit?->catch_em_all,
            'image' => $fursuit?->image,
            'fursuit_updated_at' => $fursuit?->updated_at?->toIso8601String(),
        ]));
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Badge print file generation failed', [
            'badge_id' => $this->badge->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
