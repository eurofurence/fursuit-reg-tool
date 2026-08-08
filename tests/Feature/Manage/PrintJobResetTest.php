<?php

/*
 * Bulk reset to pending: the repair for a run that stopped moving.
 *
 * What it has to get right is the shape of the failure it exists for. A run stalls with
 * its jobs spread across Queued, Printing and Failed, and an operator standing at a
 * printer needs all of them claimable again in one gesture - in place, keeping their
 * sequence, keeping their batch, carrying no lease from the agent that died and no error
 * text from the attempt that failed.
 *
 * The two refusals matter as much: a printed card must never be quietly queued a second
 * time, and one bad row in a selection must leave every other row alone rather than
 * resetting half a run.
 */

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

const MANAGE_RESET_TOAST = 'inertia.flash_data.toast.title';
const MANAGE_RESET_TOAST_BODY = 'inertia.flash_data.toast.body';

beforeEach(function () {
    Storage::fake('s3');

    $this->event = Event::factory()->create([
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    $this->printer = Printer::factory()->badge()->create();
    $this->machine = Machine::factory()->create();
    $this->batch = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);

    $this->job = function (array $attributes = []) {
        $owner = User::factory()->create();

        $badge = Badge::factory()->create([
            'status_fulfillment' => 'processing',
            'status_payment' => 'paid',
            'fursuit_id' => Fursuit::factory()->create([
                'event_id' => $this->event->id,
                'user_id' => $owner->id,
                'species_id' => Species::factory()->create()->id,
            ])->id,
        ]);

        return PrintJob::factory()->create([
            'printer_id' => $this->printer->id,
            'print_batch_id' => $this->batch->id,
            'printable_type' => Badge::class,
            'printable_id' => $badge->id,
            'type' => PrintJobTypeEnum::Badge,
            'status' => PrintJobStatusEnum::Pending,
            ...$attributes,
        ]);
    };
});

test('a claimed job comes back to pending without its lease or its machine', function () {
    $job = ($this->job)([
        'status' => PrintJobStatusEnum::Queued,
        'processing_machine_id' => $this->machine->id,
        'lease_expires_at' => now()->addMinutes(3),
        'attempt_count' => 2,
        'sequence' => 4,
    ]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$job->id]])
        ->assertSessionHas(MANAGE_RESET_TOAST, '1 print job reset to pending');

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($job->processing_machine_id)->toBeNull()
        ->and($job->lease_expires_at)->toBeNull()
        // The reaper's counter starts again: a reset is a person saying they have dealt
        // with whatever made the agent drop this card, so --max-attempts must not fail it
        // on its very next claim.
        ->and($job->attempt_count)->toBe(0)
        // In place: the card keeps its position in the run rather than being re-made at
        // the end of it, which is what Retry would do.
        ->and($job->sequence)->toBe(4)
        ->and($job->print_batch_id)->toBe($this->batch->id);
});

test('a failed job comes back to pending with its error and attempt count cleared', function () {
    $job = ($this->job)([
        'status' => PrintJobStatusEnum::Failed,
        'error_message' => 'Printer out of media',
        'failed_at' => now(),
        'attempt_count' => 2,
    ]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$job->id]]);

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($job->error_message)->toBeNull()
        ->and($job->failed_at)->toBeNull()
        ->and($job->attempt_count)->toBe(0);
});

test('a job that was mid-card comes back to pending', function () {
    $job = ($this->job)([
        'status' => PrintJobStatusEnum::Printing,
        'processing_machine_id' => $this->machine->id,
        'lease_expires_at' => now()->addMinutes(3),
    ]);

    actingAs($this->admin)->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$job->id]]);

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Pending);
});

test('a whole stalled run is reset in one gesture', function () {
    $queued = ($this->job)(['status' => PrintJobStatusEnum::Queued, 'sequence' => 1]);
    $failed = ($this->job)(['status' => PrintJobStatusEnum::Failed, 'sequence' => 2]);
    $pending = ($this->job)(['status' => PrintJobStatusEnum::Pending, 'sequence' => 3]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$queued->id, $failed->id, $pending->id]])
        // The one already Pending had nothing to do and is not counted as reset.
        ->assertSessionHas(MANAGE_RESET_TOAST, '2 print jobs reset to pending');

    expect(PrintJob::whereIn('id', [$queued->id, $failed->id, $pending->id])->pluck('status')->all())
        ->each->toBe(PrintJobStatusEnum::Pending);
});

test('a printed card in the selection resets nothing at all', function () {
    $queued = ($this->job)(['status' => PrintJobStatusEnum::Queued]);
    $printed = ($this->job)(['status' => PrintJobStatusEnum::Printed, 'printed_at' => now()]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$queued->id, $printed->id]])
        ->assertSessionHas(MANAGE_RESET_TOAST, 'Nothing was reset');

    expect($queued->fresh()->status)->toBe(PrintJobStatusEnum::Queued)
        ->and($printed->fresh()->status)->toBe(PrintJobStatusEnum::Printed);
});

test('a cancelled card in the selection resets nothing at all', function () {
    $queued = ($this->job)(['status' => PrintJobStatusEnum::Queued]);
    $cancelled = ($this->job)(['status' => PrintJobStatusEnum::Cancelled]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$queued->id, $cancelled->id]])
        ->assertSessionHas(MANAGE_RESET_TOAST, 'Nothing was reset');

    expect($queued->fresh()->status)->toBe(PrintJobStatusEnum::Queued);
});

test('the reset recalculates the counters of every batch it touched', function () {
    $failed = ($this->job)(['status' => PrintJobStatusEnum::Failed]);
    ($this->job)(['status' => PrintJobStatusEnum::Printed, 'printed_at' => now()]);

    $this->batch->recalculateCounters();

    expect($this->batch->fresh()->failed_count)->toBe(1);

    actingAs($this->admin)->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$failed->id]]);

    expect($this->batch->fresh()->failed_count)->toBe(0)
        ->and($this->batch->fresh()->printed_count)->toBe(1);
});

test('a reset inside a paused run says the run still has to be resumed', function () {
    $this->batch->update(['status' => PrintBatchStatusEnum::Paused, 'name' => 'Friday morning']);

    $job = ($this->job)(['status' => PrintJobStatusEnum::Failed]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$job->id]])
        ->assertSessionHas(
            MANAGE_RESET_TOAST_BODY,
            'Their run is not printing right now (Friday morning). Resume it from the batch page to send these cards.'
        );

    // The reset does not resume the run on the operator's behalf.
    expect($this->batch->fresh()->status)->toBe(PrintBatchStatusEnum::Paused);
});

test('a reviewer cannot reset print jobs', function () {
    $job = ($this->job)(['status' => PrintJobStatusEnum::Queued]);

    actingAs($this->reviewer)
        ->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$job->id]])
        ->assertForbidden();

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Queued);
});

test('a guest is redirected to login', function () {
    $job = ($this->job)(['status' => PrintJobStatusEnum::Queued]);

    $this->post(route('admin.print-jobs.bulk.reset'), ['ids' => [$job->id]])
        ->assertRedirect(route('login'));

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Queued);
});
