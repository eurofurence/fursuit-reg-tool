<?php

use App\Domain\Printing\Exceptions\StalePrintFileException;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;

/**
 * Generation has to happen before batching, and until now nothing enforced it.
 *
 * Invalidation marks a badge's file stale rather than re-rendering it, so there
 * is a real window in which a badge carries a PDF that no longer matches the
 * order. Building a batch is the point of no return: the sequence freezes, the
 * badge locks, and the next thing that happens is a card coming out of a
 * printer. So the batch refuses, names the badges, and says what to run.
 */
it('refuses to batch a badge that has never been rendered', function () {
    $badge = Badge::factory()->create(['custom_id' => '1234-1']);

    expect(fn () => PrintBatch::build('Friday 14:00', collect([$badge]), Printer::factory()->badge()->create()))
        ->toThrow(StalePrintFileException::class, '1234-1');
});

it('refuses to batch a badge whose artwork changed after it was rendered', function () {
    $badge = Badge::factory()->withPrintFile()->create(['custom_id' => '1234-2']);

    // The attendee renames the fursuit. The observer clears the fingerprint,
    // and until the next generation pass the stored PDF shows the old name.
    $badge->fursuit->update(['name' => 'A Brand New Name']);

    expect(fn () => PrintBatch::build('Friday 14:00', collect([$badge->fresh()]), Printer::factory()->badge()->create()))
        ->toThrow(StalePrintFileException::class, '1234-2');
});

it('refuses to batch a badge whose file was cleared but whose hash survived', function () {
    $badge = Badge::factory()->withPrintFile()->create();
    $badge->forceFill(['print_file_path' => null])->saveQuietly();

    // A matching fingerprint with nothing behind it is worse than a stale one:
    // the job would be created with a null file and fail at the printer.
    expect(fn () => PrintBatch::build('Friday 14:00', collect([$badge->fresh()]), Printer::factory()->badge()->create()))
        ->toThrow(StalePrintFileException::class);
});

it('names every offending badge, not just the first', function () {
    $good = Badge::factory()->withPrintFile()->create(['custom_id' => '1000-1']);
    $stale = Badge::factory()->create(['custom_id' => '2000-1']);
    $missing = Badge::factory()->create(['custom_id' => '3000-1']);

    // An operator who has to build the batch again wants the whole list in one
    // go, not one badge per attempt.
    try {
        PrintBatch::build('Friday 14:00', collect([$good, $stale, $missing]), Printer::factory()->badge()->create());
        $this->fail('Expected the batch to be refused.');
    } catch (StalePrintFileException $exception) {
        expect($exception->getMessage())
            ->toContain('2000-1')
            ->toContain('3000-1')
            ->not->toContain('1000-1')
            ->toContain('badges:generate-print-files')
            ->and($exception->badges->pluck('id')->all())->toBe([$stale->id, $missing->id]);
    }
});

it('creates nothing at all when it refuses', function () {
    $badge = Badge::factory()->create();

    try {
        PrintBatch::build('Friday 14:00', collect([$badge]), Printer::factory()->badge()->create());
    } catch (StalePrintFileException) {
        // Expected.
    }

    // A half-built batch would be worse than none: the badge would be locked
    // out of editing with no run to justify it.
    expect(PrintBatch::count())->toBe(0)
        ->and($badge->fresh()->isPrintingLocked())->toBeFalse();
});

it('accepts badges that a generation pass has just produced', function () {
    $badges = Badge::factory()->withPrintFile()->count(2)->create();

    $batch = PrintBatch::build('Friday 14:00', $badges, Printer::factory()->badge()->create());

    expect($batch->printJobs()->count())->toBe(2)
        ->and($batch->printJobs()->pluck('file')->filter()->count())->toBe(2);
});

it('re-accepts a stale badge once it has been regenerated', function () {
    $badge = Badge::factory()->withPrintFile()->create();
    $badge->fursuit->update(['name' => 'A Brand New Name']);

    // What the operator is told to do: run the generation pass, then build.
    $regenerated = $badge->fresh(['fursuit.species', 'fursuit.event']);
    $regenerated->forceFill([
        'print_file_hash' => GenerateBadgePrintFileJob::inputHash($regenerated),
    ])->saveQuietly();

    $batch = PrintBatch::build('Friday 14:00', collect([$regenerated->fresh()]), Printer::factory()->badge()->create());

    expect($batch->printJobs()->count())->toBe(1);
});
