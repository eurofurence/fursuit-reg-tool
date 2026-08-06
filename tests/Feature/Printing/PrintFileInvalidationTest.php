<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;

/**
 * A rendered card has to keep matching the order behind it. When anything that
 * appears on the card changes the file is marked stale, so the next generation
 * pass re-renders it and no batch can be built from outdated artwork. Once the
 * badge is committed to a batch it freezes instead.
 */
beforeEach(function () {
    $this->badge = Badge::factory()->create();
    $this->badge->forceFill([
        'print_file_path' => 'badges/'.$this->badge->id.'.pdf',
        'print_file_hash' => 'stale-hash',
        'print_file_generated_at' => now()->subHour(),
    ])->saveQuietly();
});

/**
 * Bring a badge's recorded fingerprint up to date, as a generation pass would.
 *
 * Needed by the batching cases only: PrintBatch::build() refuses stale artwork,
 * so the placeholder hash the other tests rely on would be rejected before the
 * lock these tests are actually about is ever taken.
 */
function markPrintFileCurrent(Badge $badge): string
{
    $hash = GenerateBadgePrintFileJob::inputHash($badge->fresh(['fursuit.species', 'fursuit.event']));

    $badge->forceFill(['print_file_hash' => $hash])->saveQuietly();

    return $hash;
}

it('marks the file stale when the fursuit is renamed', function () {
    $this->badge->fursuit->update(['name' => 'A Brand New Name']);

    expect($this->badge->fresh()->print_file_hash)->toBeNull()
        ->and($this->badge->fresh()->print_file_generated_at)->toBeNull();
});

it('marks the file stale when the photo is replaced', function () {
    $this->badge->fursuit->update(['image' => 'fursuits/replacement.png']);

    expect($this->badge->fresh()->print_file_hash)->toBeNull();
});

it('marks the file stale when the badge switches to duplex', function () {
    $this->badge->update(['dual_side_print' => ! $this->badge->dual_side_print]);

    expect($this->badge->fresh()->print_file_hash)->toBeNull();
});

it('ignores changes that never reach the card', function () {
    $this->badge->fursuit->update(['published' => ! $this->badge->fursuit->published]);

    expect($this->badge->fresh()->print_file_hash)->toBe('stale-hash');
});

it('makes a stale badge eligible for the next generation pass', function () {
    $this->badge->fursuit->update(['name' => 'A Brand New Name']);

    $badge = $this->badge->fresh(['fursuit.species', 'fursuit.event']);

    // A null hash can never match, so the command will pick this badge up.
    expect($badge->print_file_hash)->not->toBe(GenerateBadgePrintFileJob::inputHash($badge));
});

it('freezes a badge that is already committed to a batch', function () {
    $hash = markPrintFileCurrent($this->badge);

    PrintBatch::build('Friday 14:00', collect([$this->badge]), Printer::factory()->badge()->create());

    $this->badge->fursuit->update(['name' => 'Too Late To Change This']);

    // The card is queued or already printed. Invalidating now would let the
    // artwork be re-rendered under a card the stack already contains.
    expect($this->badge->fresh()->print_file_hash)->toBe($hash)
        ->and($this->badge->fresh()->print_file_path)->not->toBeNull();
});

it('does nothing when invalidating a locked badge directly', function () {
    $hash = markPrintFileCurrent($this->badge);

    PrintBatch::build('Friday 14:00', collect([$this->badge]), Printer::factory()->badge()->create());

    GenerateBadgePrintFileJob::invalidateFor($this->badge->fresh());

    expect($this->badge->fresh()->print_file_hash)->toBe($hash);
});
