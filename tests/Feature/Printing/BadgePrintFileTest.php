<?php

use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use Illuminate\Support\Facades\Queue;

/**
 * Rendering a badge and printing it are now separate steps, so an event's
 * artwork can be produced in one pass long before anyone stands at a printer.
 * These cover the seam between the two.
 */
beforeEach(function () {
    // Editing a fursuit queues a re-render. These tests are about the hash and
    // the handover to the printer, not about producing artwork.
    Queue::fake();
});
it('fingerprints every input that changes the artwork', function () {
    $badge = Badge::factory()->create();

    $before = GenerateBadgePrintFileJob::inputHash($badge->fresh(['fursuit.species', 'fursuit.event']));

    $badge->fursuit->update(['name' => 'A Completely Different Name']);

    $after = GenerateBadgePrintFileJob::inputHash($badge->fresh(['fursuit.species', 'fursuit.event']));

    expect($after)->not->toBe($before);
});

it('produces a stable fingerprint when nothing has changed', function () {
    $badge = Badge::factory()->create()->fresh(['fursuit.species', 'fursuit.event']);

    expect(GenerateBadgePrintFileJob::inputHash($badge))
        ->toBe(GenerateBadgePrintFileJob::inputHash($badge));
});

it('changes the fingerprint when the badge switches to duplex', function () {
    $badge = Badge::factory()->create(['dual_side_print' => false]);
    $before = GenerateBadgePrintFileJob::inputHash($badge->fresh(['fursuit.species', 'fursuit.event']));

    $badge->update(['dual_side_print' => true]);

    expect(GenerateBadgePrintFileJob::inputHash($badge->fresh(['fursuit.species', 'fursuit.event'])))
        ->not->toBe($before);
});

it('sends an already rendered badge to the printer without re-rendering', function () {
    Printer::factory()->badge()->create(['is_active' => true]);

    $badge = Badge::factory()->create([
        'print_file_path' => 'badges/preexisting.pdf',
        'print_file_hash' => 'whatever',
        'print_file_generated_at' => now()->subHour(),
    ]);

    (new PrintBadgeJob($badge))->handle();

    $job = PrintJob::query()->where('printable_id', $badge->id)->first();

    expect($job)->not->toBeNull()
        ->and($job->file)->toBe('badges/preexisting.pdf')
        ->and($job->status)->toBe(PrintJobStatusEnum::Pending)
        // Untouched: printing must not quietly re-render and change the artwork
        // out from under a card that was already approved.
        ->and($badge->fresh()->print_file_generated_at->toIso8601String())
        ->toBe($badge->print_file_generated_at->toIso8601String());
});

it('skips badges whose artwork inputs are unchanged', function () {
    $badge = Badge::factory()->create();
    $event = $badge->fursuit->event;

    // Pretend this badge was already rendered by a previous pass.
    $badge->forceFill([
        'print_file_path' => 'badges/'.$badge->id.'.pdf',
        'print_file_hash' => GenerateBadgePrintFileJob::inputHash($badge->fresh(['fursuit.species', 'fursuit.event'])),
        'print_file_generated_at' => now(),
    ])->saveQuietly();

    // Re-running must be a no-op rather than re-rendering the whole event,
    // which is what makes the command safe to run again mid-convention.
    $this->artisan('badges:generate-print-files', ['--event' => $event->id])
        ->expectsOutputToContain('0 need rendering')
        ->assertSuccessful();
});
