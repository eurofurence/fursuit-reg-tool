<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Services\BadgePrintQueue;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintCompletionSourceEnum;
use App\Models\Badge\Badge;
use App\Models\EventUser;
use App\Models\Machine;
use App\Models\Staff;
use Tests\TestCase;

/**
 * A desk clerk sends a card to a printer while the attendee stands at the
 * counter. Until this existed nothing came back: the run finished out of sight
 * and the only way to find out was to read the whole print queue.
 */

/**
 * A badge that can be queued, registered to a known attendee id.
 */
function badgeForDesk(string $attendeeId = '1234'): Badge
{
    $badge = Badge::factory()->withPrintFile()->create([
        'custom_id' => $attendeeId.'-1',
    ]);

    EventUser::factory()->create([
        'user_id' => $badge->fursuit->user_id,
        'event_id' => $badge->fursuit->event_id,
        'attendee_id' => $attendeeId,
    ]);

    return $badge;
}

function deskActingAs(Staff $staff): TestCase
{
    return test()
        ->actingAs(Machine::factory()->create(), 'machine')
        ->actingAs($staff, 'machine-user');
}

/**
 * Run every card in the batch through a printer, the way the agent would.
 */
function finishBatch(PrintBatch $batch): void
{
    if ($batch->status !== PrintBatchStatusEnum::Printing) {
        $batch->transitionTo(PrintBatchStatusEnum::Printing);
    }

    $machine = Machine::factory()->create();

    while ($job = $batch->fresh()->claimNextJob($machine)) {
        $job->markPrinting();
        $job->markPrinted(PrintCompletionSourceEnum::Firmware);
    }
}

it('records the clerk who sent a badge to the printer', function () {
    $staff = Staff::factory()->create();
    $badge = badgeForDesk();
    Printer::factory()->badge()->create();

    deskActingAs($staff)
        ->post(route('pos.badges.print', ['badge' => $badge->id]))
        ->assertRedirect();

    expect(PrintBatch::latest('id')->first()->created_by_staff_id)->toBe($staff->id);
});

it('tells the clerk on the dashboard once their run has printed', function () {
    $staff = Staff::factory()->create();
    $batch = BadgePrintQueue::queue(
        collect([badgeForDesk('1500')]),
        Printer::factory()->badge()->create(),
        createdByStaffId: $staff->id,
    );

    finishBatch($batch->fresh());

    deskActingAs($staff)
        ->get(route('pos.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('printNotifications', 1)
            ->where('printNotifications.0.status', 'completed')
            // The way back to the person waiting for the card.
            ->where('printNotifications.0.badges.0.attendee_id', '1500')
        );
});

it('says nothing while the run is still on its way', function () {
    $staff = Staff::factory()->create();

    BadgePrintQueue::queue(
        collect([badgeForDesk('1600')]),
        Printer::factory()->badge()->create(),
        createdByStaffId: $staff->id,
    );

    deskActingAs($staff)
        ->get(route('pos.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('printNotifications', 0));
});

it('keeps one clerk out of another clerk notifications', function () {
    $mine = Staff::factory()->create();
    $theirs = Staff::factory()->create();

    $batch = BadgePrintQueue::queue(
        collect([badgeForDesk('1700')]),
        Printer::factory()->badge()->create(),
        createdByStaffId: $theirs->id,
    );

    finishBatch($batch->fresh());

    deskActingAs($mine)
        ->get(route('pos.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('printNotifications', 0));
});

it('lets the clerk dismiss a notification', function () {
    $staff = Staff::factory()->create();
    $batch = BadgePrintQueue::queue(
        collect([badgeForDesk('1800')]),
        Printer::factory()->badge()->create(),
        createdByStaffId: $staff->id,
    );

    finishBatch($batch->fresh());

    deskActingAs($staff)
        ->post(route('pos.my-prints.dismiss', ['printBatch' => $batch->id]))
        ->assertRedirect();

    deskActingAs($staff)
        ->get(route('pos.dashboard'))
        ->assertInertia(fn ($page) => $page->has('printNotifications', 0));
});

it('refuses to let a clerk clear somebody else notification', function () {
    $mine = Staff::factory()->create();
    $theirs = Staff::factory()->create();

    $batch = BadgePrintQueue::queue(
        collect([badgeForDesk('1900')]),
        Printer::factory()->badge()->create(),
        createdByStaffId: $theirs->id,
    );

    finishBatch($batch->fresh());

    deskActingAs($mine)
        ->post(route('pos.my-prints.dismiss', ['printBatch' => $batch->id]));

    expect($batch->fresh()->desk_dismissed_status)->toBeNull();
});

/**
 * A run dismissed while it was stuck has to speak up again when it finishes,
 * or the clerk never learns the card came out.
 */
it('speaks again when a dismissed run moves on', function () {
    $staff = Staff::factory()->create();
    $batch = BadgePrintQueue::queue(
        collect([badgeForDesk('2000')]),
        Printer::factory()->badge()->create(),
        createdByStaffId: $staff->id,
    );

    $batch->transitionTo(PrintBatchStatusEnum::Printing);
    $batch->refresh()->pause('jam');
    $batch->refresh()->dismissForDesk();

    deskActingAs($staff)
        ->get(route('pos.dashboard'))
        ->assertInertia(fn ($page) => $page->has('printNotifications', 0));

    $batch->refresh()->resume();
    finishBatch($batch->fresh());

    deskActingAs($staff)
        ->get(route('pos.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('printNotifications', 1)
            ->where('printNotifications.0.status', 'completed')
        );
});

it('lists the clerk own runs on their print jobs page', function () {
    $staff = Staff::factory()->create();
    $batch = BadgePrintQueue::queue(
        collect([badgeForDesk('2100')]),
        Printer::factory()->badge()->create(),
        createdByStaffId: $staff->id,
    );

    finishBatch($batch->fresh());

    deskActingAs($staff)
        ->get(route('pos.my-prints.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('POS/MyPrints/Index')
            ->has('batches', 1)
            ->where('batches.0.badges.0.attendee_id', '2100')
        );
});

/**
 * The POS attendee page is looked up by attendee id, so a badge whose
 * registration is gone has nothing to open. It still has to appear in the list
 * rather than take the dashboard down with it.
 */
it('handles a badge whose attendee has no registration', function () {
    $staff = Staff::factory()->create();
    $badge = badgeForDesk('2200');

    $batch = BadgePrintQueue::queue(
        collect([$badge]),
        Printer::factory()->badge()->create(),
        createdByStaffId: $staff->id,
    );

    finishBatch($batch->fresh());

    // The registration is dropped after the card is printed: the attendee
    // cancelled, or the row was cleaned up while the run was still going.
    EventUser::where('user_id', $badge->fursuit->user_id)->delete();

    deskActingAs($staff)
        ->get(route('pos.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('printNotifications.0.badges.0.attendee_id', null)
            ->where('printNotifications.0.badges.0.attendee_url', null)
        );
});
