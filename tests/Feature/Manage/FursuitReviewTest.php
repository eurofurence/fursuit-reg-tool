<?php

/*
 * The review queue: three outcomes, an undo window, advisory presence.
 *
 * What this locks in, and why each one is here:
 *
 *  - **Three outcomes, not two.** Approval used to be yes/no, so a photo that broke a
 *    gallery rule but no rule in the Code of Conduct could only be rejected - and a
 *    rejected fursuit is never printed and never handed out, so the attendee lost a badge
 *    over a gallery rule. A publication block keeps the card and closes only the gallery
 *    and Catch-Em-All.
 *  - **A Code of Conduct rejection actually stops the card.** Nothing enforced that before:
 *    printing looked only at the badge, so a rejected submission was printed and handed out
 *    by every print entry point and the rejection only ever meant an email.
 *  - **The undo window is a column, not a queue delay.** A `->delay()` is ignored by the
 *    `sync` driver - which this suite uses - so the mail would go out inside the reviewer's
 *    own request and the arrow-back would be a lie.
 *  - **Presence is advisory.** The lock it replaced refused verdicts, so a reviewer who
 *    followed a link could do nothing and a dead browser froze a record for five minutes.
 */

use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Services\BadgePrintQueue;
use App\Enum\FursuitReviewOutcomeEnum;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\Species;
use App\Models\User;
use App\Notifications\FursuitApprovedNotification;
use App\Notifications\FursuitPublicationBlockedNotification;
use App\Notifications\FursuitRejectedNotification;
use App\Services\FursuitReviewService;
use App\Support\Manage\EventScope;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Storage::fake('s3');
    Notification::fake();

    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
    ]);

    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->second = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    $this->species = Species::create(['name' => 'Wolf']);

    // A fursuit always has a main badge in production, because ordering the badge is what
    // creates the fursuit - and the notifications read that badge in their constructor.
    $this->fursuit = function (array $attributes = []) {
        $fursuit = Fursuit::factory()->create([
            'event_id' => $this->event->id,
            'species_id' => $this->species->id,
            'status' => Pending::$name,
            'name' => 'Fluffy',
            'image' => 'fursuits/fluffy.jpg',
            'published' => true,
            'catch_em_all' => true,
            ...$attributes,
        ]);

        Badge::factory()->create(['fursuit_id' => $fursuit->id, 'extra_copy_of' => null]);

        return $fursuit;
    };

    $this->scoped = fn (User $user) => actingAs($user)->withSession([
        EventScope::SESSION_ID => $this->event->id,
        EventScope::SESSION_CHOSEN => true,
    ]);

    // The window has to pass before anybody is told. Every test that wants the mail says so
    // explicitly by calling this.
    $this->deliver = function () {
        $this->travel(FursuitReviewService::UNDO_WINDOW_MINUTES + 1)->minutes();
        artisan('fursuits:deliver-review-decisions')->assertSuccessful();
        $this->travelBack();
    };
});

/*
|--------------------------------------------------------------------------
| The queue page
|--------------------------------------------------------------------------
*/

test('the queue hands out the oldest waiting fursuit and the page carries the three outcomes', function () {
    $first = ($this->fursuit)();
    ($this->fursuit)(['name' => 'Later']);

    ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review'))
        ->assertRedirect(route('manage.fursuits.review.show', $first));

    ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review.show', $first))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Fursuits/Review')
            ->where('fursuit.name', 'Fluffy')
            ->where('fursuit.species', 'Wolf')
            ->where('fursuit.publication.blocked', false)
            ->where('queue.remaining', 2)
            // Every outcome ships its shortcut, its consequence and its own reason list, so
            // the page cannot disagree with the server about what a key does.
            ->count('outcomes', 3)
            ->where('outcomes.0.value', 'approved')
            ->where('outcomes.0.shortcut', 'a')
            ->where('outcomes.0.requiresReason', false)
            ->where('outcomes.1.value', 'rejected')
            ->where('outcomes.1.shortcut', 'r')
            ->where('outcomes.1.requiresReason', true)
            ->where('outcomes.2.value', 'publication_blocked')
            ->where('outcomes.2.shortcut', 'g')
            ->where('outcomes.2.requiresReason', true)
            ->where('outcomes.2.consequence', 'Prints and is handed out, but never shown in the gallery or the game.')
            ->where('undo', null)
        );
});

test('the queue skips a record another reviewer is on, and says so if you follow a link', function () {
    $taken = ($this->fursuit)(['name' => 'Taken']);
    $free = ($this->fursuit)(['name' => 'Free']);

    ($this->scoped)($this->second)->get(route('manage.fursuits.review.show', $taken))->assertSuccessful();

    ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review'))
        ->assertRedirect(route('manage.fursuits.review.show', $free));

    ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review.show', $taken))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('presence.others.0.name', $this->second->name));
});

test('the queue hands out a busy record rather than claiming the queue is empty', function () {
    // The skip is a courtesy. With one record left and somebody on it, refusing to hand it
    // over would tell the reviewer there is nothing to do while the backlog is not empty.
    $only = ($this->fursuit)();

    ($this->scoped)($this->second)->get(route('manage.fursuits.review.show', $only))->assertSuccessful();

    ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review'))
        ->assertRedirect(route('manage.fursuits.review.show', $only));
});

test('an empty queue lands on the list and says so', function () {
    ($this->fursuit)(['status' => Approved::$name]);

    ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review'))
        ->assertRedirect(route('manage.fursuits.index'))
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Nothing left to review',
            'body' => 'No pending fursuits are waiting in the selected event.',
        ]);
});

/*
|--------------------------------------------------------------------------
| The three outcomes
|--------------------------------------------------------------------------
*/

test('a verdict from the queue advances inside the queue, not to the record page', function () {
    $fursuit = ($this->fursuit)();
    $next = ($this->fursuit)(['name' => 'Next']);

    ($this->scoped)($this->reviewer)
        ->post(route('manage.fursuits.approve', [$fursuit, 'queue' => 1]))
        ->assertRedirect(route('manage.fursuits.review.show', $next));

    // Without the flag the same endpoint keeps the reviewer on record pages.
    ($this->scoped)($this->reviewer)
        ->post(route('manage.fursuits.approve', $next))
        ->assertRedirect(route('manage.fursuits.index'));
});

test('a publication block approves the badge and closes only the public surfaces', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit), [
        'reason' => 'not_a_photo',
        'custom_reason' => 'Your image is not a photo of a fursuit.',
    ])->assertRedirect();

    $fursuit->refresh();

    expect($fursuit->status)->toBeInstanceOf(Approved::class)
        ->and($fursuit->approved_at)->not->toBeNull()
        // The card is printable: that is the whole point of this outcome.
        ->and($fursuit->isPrintable())->toBeTrue()
        ->and($fursuit->isPublicationBlocked())->toBeTrue()
        ->and($fursuit->publication_block_reason)->toBe('Your image is not a photo of a fursuit.')
        // The two attendee switches are turned off as well, because `catch_em_all` is read
        // by the badge artwork and the catch-code lookup, and a printed QR that no longer
        // resolves is worse than no QR.
        ->and($fursuit->published)->toBeFalse()
        ->and($fursuit->catch_em_all)->toBeFalse()
        ->and($fursuit->isPublishable())->toBeFalse();

    ($this->deliver)();

    Notification::assertSentTo(
        $fursuit->user,
        fn (FursuitPublicationBlockedNotification $mail) => $mail->reason === 'Your image is not a photo of a fursuit.',
    );

    // Not the rejection mail: nothing is required of the attendee.
    Notification::assertNotSentTo($fursuit->user, FursuitRejectedNotification::class);
});

test('a blocked fursuit stays out of the gallery even if the attendee flips the switch back on', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    // The attendee's switch is a request; the block is the answer to it.
    $fursuit->forceFill(['published' => true, 'catch_em_all' => true])->save();

    expect(Fursuit::query()->publicationAllowed()->whereKey($fursuit->id)->exists())->toBeFalse();
});

test('lifting a block restores the switches the attendee had, not blanket publication', function () {
    // An attendee who never asked for the game must not be entered into it by a reviewer
    // undoing their own mistake.
    $fursuit = ($this->fursuit)(['published' => true, 'catch_em_all' => false]);

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    ($this->scoped)($this->reviewer)->delete(route('manage.fursuits.unblock-publication', $fursuit))
        ->assertRedirect();

    $fursuit->refresh();

    expect($fursuit->isPublicationBlocked())->toBeFalse()
        ->and($fursuit->published)->toBeTrue()
        ->and($fursuit->catch_em_all)->toBeFalse()
        ->and($fursuit->status)->toBeInstanceOf(Approved::class);
});

test('approving a blocked fursuit clears the block', function () {
    // "All clear" after a resubmission. Leaving the block in place would keep the fursuit
    // out of the gallery forever with nothing on screen explaining why.
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.approve', $fursuit))->assertRedirect();

    expect($fursuit->fresh()->isPublicationBlocked())->toBeFalse();
});

test('a second block is not offered while one stands, and a lift is', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    $outcomes = collect(
        ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review.show', $fursuit))
            ->viewData('page')['props']['outcomes']
    )->keyBy('value');

    expect($outcomes['publication_blocked']['available'])->toBeFalse()
        ->and($outcomes['publication_blocked']['unavailableReason'])
        ->toContain('already blocked from the gallery')
        // Approving is still available, because that is how the block is cleared.
        ->and($outcomes['approved']['available'])->toBeTrue();

    $actions = collect(
        actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->pluck('name');

    expect($actions)->toContain('unblock-publication');
});

test('a block on somebody who never asked to be published is recorded as a plain approval', function () {
    /*
     * The reviewer still reaches for Block - it is the obvious button for "this is not a photo
     * of a suit" - but there is nothing to refuse: the attendee ticked neither the gallery nor
     * the game. Telling them a request they never made has been refused would be worse than
     * telling them nothing, so it becomes an approval and the screen says so.
     */
    $fursuit = ($this->fursuit)(['published' => false, 'catch_em_all' => false]);

    // And it needs no reason, so the verdict is still one keystroke.
    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit))
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Approved, not published',
            'body' => 'The attendee asked for neither the gallery nor the game, so this was approved with nothing to block and nothing to explain to them.',
        ]);

    $fursuit->refresh();

    expect($fursuit->status)->toBeInstanceOf(Approved::class)
        ->and($fursuit->isPublicationBlocked())->toBeFalse()
        ->and($fursuit->latestReviewDecision()->outcome)->toBe(FursuitReviewOutcomeEnum::Approved)
        ->and($fursuit->latestReviewDecision()->reason)->toBeNull();

    ($this->deliver)();

    // The ordinary approval mail, not the one about not being published.
    Notification::assertSentTo($fursuit->user, FursuitApprovedNotification::class);
    Notification::assertNotSentTo($fursuit->user, FursuitPublicationBlockedNotification::class);
});

test('the queue page says the block will be a plain approval before it is pressed', function () {
    $fursuit = ($this->fursuit)(['published' => false, 'catch_em_all' => false]);

    $outcomes = collect(
        ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review.show', $fursuit))
            ->viewData('page')['props']['outcomes']
    )->keyBy('value');

    expect($outcomes['publication_blocked']['silentApproval'])->toBeTrue()
        ->and($outcomes['publication_blocked']['requiresReason'])->toBeFalse()
        ->and($outcomes['publication_blocked']['consequence'])
        ->toBe('The attendee asked for neither the gallery nor the game, so this is recorded as a plain approval.');
});

test('wanting only the game is still a request worth blocking', function () {
    // Publication is two surfaces. Reading only `published` would silently approve a fursuit
    // that asked to be catchable.
    $fursuit = ($this->fursuit)(['published' => false, 'catch_em_all' => true]);

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit))
        ->assertSessionHasErrors('custom_reason');

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ])->assertRedirect();

    expect($fursuit->fresh()->isPublicationBlocked())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Submission history
|--------------------------------------------------------------------------
*/

test('the page shows the earlier version and whether the photo actually changed', function () {
    /*
     * The question a reviewer has on every resubmission. An attendee told that their image is
     * not a photo of a costume, who then sends the same file back, is indistinguishable from
     * one who fixed it unless both versions are on screen.
     */
    $fursuit = ($this->fursuit)(['name' => 'Fluffy', 'image' => 'fursuits/first.jpg']);

    // A new photo: a revision is written by the observer, whichever write path made the change.
    $fursuit->refresh();
    $fursuit->update(['image' => 'fursuits/second.jpg']);

    // And a name-only edit on top of it.
    $fursuit->refresh();
    $fursuit->update(['name' => 'Fluffy II']);

    $card = ($this->scoped)($this->reviewer)->get(route('manage.fursuits.review.show', $fursuit))
        ->viewData('page')['props']['fursuit'];

    expect($card['history'])->toHaveCount(2)
        // Newest first: the name-only edit, whose photo is the one the record still has.
        ->and($card['history'][0]['name'])->toBe('Fluffy')
        ->and($card['history'][0]['imageChanged'])->toBeFalse()
        ->and($card['history'][0]['nameChanged'])->toBeTrue()
        // Then the version whose photo was replaced.
        ->and($card['history'][1]['imageChanged'])->toBeTrue()
        ->and($card['history'][1]['image'])->toContain('fursuits/first.jpg')
        ->and($card['history'][1]['changedBy'])->toBeNull();
});

test('the attendee editor keeps the photo it replaces, so the history has a picture', function () {
    // It used to Storage::delete() the old file, which left every history entry pointing at
    // nothing - and "is this the same image?" unanswerable.
    Storage::fake('s3');

    $fursuit = ($this->fursuit)();
    // The editor is only open on a badge that is still Pending fulfillment and not committed
    // to a batch, which is what BadgePolicy::updateAsOwner() asks (the factory randomises it).
    $badge = $fursuit->badges()->sole();
    $badge->forceFill([
        'status_fulfillment' => App\Models\Badge\State_Fulfillment\Pending::$name,
        'printed_at' => null,
    ])->saveQuietly();

    /*
     * `ensure-event-user` guards the attendee routes and sends anyone without a valid
     * registration for the active event back to the welcome page. `valid_registration` is a
     * coin flip in the factory, so it is stated here - left to chance this test passes four
     * runs out of five.
     */
    EventUser::factory()->create([
        'user_id' => $fursuit->user_id,
        'event_id' => $this->event->id,
        'valid_registration' => true,
    ]);

    $original = $fursuit->image;

    Storage::disk('s3')->put($original, 'first');

    actingAs($fursuit->user)->put(route('badges.update', $badge), [
        'name' => 'Fluffy',
        'species' => 'Wolf',
        'publish' => true,
        'catchEmAll' => false,
        'image' => UploadedFile::fake()->image('second.jpg', 2040, 2720),
    ])->assertRedirect(route('badges.index'));

    $fursuit->refresh();

    expect($fursuit->image)->not->toBe($original)
        ->and(Storage::disk('s3')->exists($original))->toBeTrue()
        ->and($fursuit->submissionRevisions()->latest('id')->first()->image)->toBe($original)
        // The attendee's change sends the record back to the queue and drops the publication
        // verdict with it, so the new photo is judged afresh.
        ->and($fursuit->status)->toBeInstanceOf(Pending::class);
});

/*
|--------------------------------------------------------------------------
| Undo
|--------------------------------------------------------------------------
*/

test('undo restores the record and nothing reaches the attendee', function () {
    $fursuit = ($this->fursuit)();
    ($this->fursuit)(['name' => 'Next']);

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.reject', [$fursuit, 'queue' => 1]), [
        'custom_reason' => 'Wrong for the wrong reasons.',
    ])->assertRedirect();

    expect($fursuit->fresh()->status)->toBeInstanceOf(Rejected::class);

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.review.undo'))
        ->assertRedirect(route('manage.fursuits.review.show', $fursuit))
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Decision undone',
            'body' => 'Rejected (Code of Conduct) on Fluffy was taken back. Nothing was sent to the attendee.',
        ]);

    $fursuit->refresh();

    // A restore, not a transition: the machine has no rejected -> pending edge, and the two
    // timestamps come back exactly as they were rather than being stamped again.
    expect($fursuit->status)->toBeInstanceOf(Pending::class)
        ->and($fursuit->rejected_at)->toBeNull()
        ->and($fursuit->approved_at)->toBeNull();

    ($this->deliver)();

    Notification::assertNothingSent();

    // And the row is kept, marked, so the log still says what happened.
    $decision = $fursuit->reviewDecisions()->sole();

    expect($decision->undone_at)->not->toBeNull()
        ->and($decision->undone_by_id)->toBe($this->reviewer->id)
        ->and($decision->notified_at)->toBeNull();
});

test('undo is refused once the attendee has been told', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.approve', $fursuit));

    ($this->deliver)();

    Notification::assertSentTo($fursuit->user, FursuitApprovedNotification::class);

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.review.undo'))
        ->assertInertiaFlash('toast', [
            'tone' => 'warning',
            'title' => 'Nothing to undo',
            'body' => 'Your last decision has already been sent to the attendee, or somebody has decided again since.',
        ]);

    expect($fursuit->fresh()->status)->toBeInstanceOf(Approved::class);
});

test('undo reaches only your own last verdict', function () {
    $mine = ($this->fursuit)(['name' => 'Mine']);
    $theirs = ($this->fursuit)(['name' => 'Theirs']);

    ($this->scoped)($this->second)->post(route('manage.fursuits.approve', $theirs));
    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.approve', $mine));

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.review.undo'))->assertRedirect();

    expect($mine->fresh()->status)->toBeInstanceOf(Pending::class)
        ->and($theirs->fresh()->status)->toBeInstanceOf(Approved::class);
});

test('a superseded verdict is neither undoable nor announced', function () {
    // Two reviewers on one record, which presence makes unlikely and does not prevent.
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.approve', $fursuit));
    ($this->scoped)($this->second)->post(route('manage.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.review.undo'))
        ->assertInertiaFlash('toast', [
            'tone' => 'warning',
            'title' => 'Nothing to undo',
            'body' => 'Your last decision has already been sent to the attendee, or somebody has decided again since.',
        ]);

    ($this->deliver)();

    // Only the standing verdict is announced. The attendee never hears about the approval
    // that was replaced a second later.
    Notification::assertSentTo($fursuit->user, FursuitPublicationBlockedNotification::class);
    Notification::assertNotSentTo($fursuit->user, FursuitApprovedNotification::class);
});

test('a verdict is not announced after the attendee has resubmitted', function () {
    // Resubmitting resets the record without writing a decision row, so the verdict would
    // otherwise describe a submission that no longer exists.
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.reject', $fursuit), [
        'custom_reason' => 'Please change the photo.',
    ]);

    /*
     * refresh() first. The POST above changed the row, so this instance still holds the old
     * state and forceFill would find nothing dirty to write - which is also how the
     * attendee's own request behaves: it loads the record fresh.
     */
    $fursuit->refresh();
    $fursuit->forceFill(['status' => Pending::$name, 'rejected_at' => null])->save();

    ($this->deliver)();

    Notification::assertNothingSent();

    expect($fursuit->reviewDecisions()->sole()->notified_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| What a verdict is worth downstream
|--------------------------------------------------------------------------
*/

test('a Code of Conduct rejection stops the card, a publication block does not', function () {
    /*
     * The gate nothing enforced before this: printing looked only at the badge, so a
     * rejected fursuit - a submission a reviewer had refused, with the attendee already
     * asked to change it - was printed and handed out by every print entry point.
     */
    $rejected = ($this->fursuit)(['name' => 'Refused']);
    $blocked = ($this->fursuit)(['name' => 'Not published']);
    $pending = ($this->fursuit)(['name' => 'Still waiting']);

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.reject', $rejected), [
        'custom_reason' => 'Against the Code of Conduct.',
    ]);
    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $blocked), [
        'custom_reason' => 'Not a costume.',
    ]);

    /*
     * The blocked card is the only one that reaches the printer, so it is the only one that
     * needs to look printable: an EventUser for the attendee id ToProcessing allocates from,
     * and a print file, because BadgePrintQueue renders one synchronously and rendering a
     * real PDF here would drag badge artwork into a gate test.
     */
    EventUser::factory()->create([
        'user_id' => $blocked->user_id,
        'event_id' => $blocked->event_id,
    ]);

    $blockedBadge = $blocked->badges()->sole();
    $blockedBadge->forceFill(['custom_id' => '1001-1'])->saveQuietly();
    $blockedBadge->forceFill([
        'print_file_path' => 'badges/'.$blockedBadge->id.'.pdf',
        'print_file_hash' => GenerateBadgePrintFileJob::inputHash($blockedBadge->fresh(['fursuit.species', 'fursuit.event'])),
        'print_file_renderer' => 'EF30_Badge',
        'print_file_generated_at' => now(),
    ])->saveQuietly();

    $printer = Printer::factory()->badge()->create();

    // The rejected one is dropped, and so is the one still waiting for a verdict: neither
    // has been cleared.
    expect(BadgePrintQueue::queue(collect([$rejected->badges()->sole()]), $printer))->toBeNull()
        ->and(BadgePrintQueue::queue(collect([$pending->badges()->sole()]), $printer))->toBeNull();

    $batch = BadgePrintQueue::queue(collect([$blockedBadge->fresh()]), $printer);

    expect($batch)->not->toBeNull()
        ->and($batch->total_jobs)->toBe(1);
});

test('the decision row carries what undo needs and nothing the attendee did not get', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.approve', $fursuit));

    $decision = $fursuit->reviewDecisions()->sole();

    expect($decision->outcome)->toBe(FursuitReviewOutcomeEnum::Approved)
        // No reason on an approval: there is nothing to explain.
        ->and($decision->reason)->toBeNull()
        ->and($decision->reviewer_id)->toBe($this->reviewer->id)
        ->and($decision->restore['status'])->toBe('pending')
        ->and($decision->restore['published'])->toBeTrue()
        ->and($decision->notify_at->greaterThan(now()))->toBeTrue()
        ->and($decision->notified_at)->toBeNull();
});

test('the two reason lists are separate, and each verdict validates against its own', function () {
    // The eight rejection strings all tell the attendee to fix their badge, which is wrong
    // for a badge that is approved and being printed.
    $rejectionKeys = array_keys(FursuitReviewService::REASONS[FursuitReviewOutcomeEnum::Rejected->value]);
    $blockKeys = array_keys(FursuitReviewService::REASONS[FursuitReviewOutcomeEnum::PublicationBlocked->value]);

    expect($rejectionKeys)->toHaveCount(8)
        ->and($blockKeys)->toContain('not_a_photo', 'ai_generated', 'real_animal', 'no_costume')
        ->and(FursuitReviewService::REASONS[FursuitReviewOutcomeEnum::PublicationBlocked->value]['not_a_photo'])
        ->toContain('gallery');

    $fursuit = ($this->fursuit)();

    // A rejection slug is not a publication-block slug.
    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit), [
        'reason' => 'explicit',
        'custom_reason' => 'Anything',
    ])->assertSessionHasErrors('reason');

    ($this->scoped)($this->reviewer)->post(route('manage.fursuits.block-publication', $fursuit), [
        'reason' => 'no_costume',
    ])->assertSessionHasErrors('custom_reason');

    expect($fursuit->fresh()->isPublicationBlocked())->toBeFalse();
});

test('an outsider reaches none of it', function () {
    $outsider = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);
    $fursuit = ($this->fursuit)();

    actingAs($outsider);

    get(route('manage.fursuits.review'))->assertForbidden();
    get(route('manage.fursuits.review.show', $fursuit))->assertForbidden();
    post(route('manage.fursuits.review.undo'))->assertForbidden();
    post(route('manage.fursuits.block-publication', $fursuit), ['custom_reason' => 'x'])->assertForbidden();
});
