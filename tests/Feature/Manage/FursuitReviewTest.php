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
use App\Jobs\GenerateFursuitWebpJob;
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
use Illuminate\Support\Facades\Queue;
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

        /*
         * Stamp the gallery renders on, because the queue holds back a record whose render is still
         * in flight (Fursuit::imageRenderSettled) and a fixture is meant to describe the settled
         * case. It has to happen after create and quietly: FursuitObserver clears the variants when
         * the photo changes, and GenerateFursuitWebpJob - running sync against a faked disk - writes
         * nothing back. A test that wants the few seconds after an upload nulls them again itself.
         */
        $fursuit->forceFill([
            'image_webp' => GenerateFursuitWebpJob::pathFor($fursuit->image),
            'image_thumb' => GenerateFursuitWebpJob::thumbPathFor($fursuit->image),
        ])->saveQuietly();

        return $fursuit->refresh();
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

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review'))
        ->assertRedirect(route('admin.fursuits.review.show', $first));

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $first))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Fursuits/Review')
            ->where('fursuit.name', 'Fluffy')
            ->where('fursuit.species', 'Wolf')
            ->where('fursuit.publication.blocked', false)
            ->where('queue.remaining', 2)
            // Every outcome ships its keys, its consequence and its own reason list, so the
            // page cannot disagree with the server about what a key does.
            ->count('outcomes', 3)
            ->where('outcomes.0.value', 'approved')
            ->where('outcomes.0.shortcuts', ['a'])
            ->where('outcomes.0.requiresReason', false)
            ->where('outcomes.1.value', 'rejected')
            ->where('outcomes.1.shortcuts', ['r'])
            ->where('outcomes.1.requiresReason', true)
            ->where('outcomes.2.value', 'publication_blocked')
            ->where('outcomes.2.shortcuts', ['g'])
            ->where('outcomes.2.requiresReason', true)
            ->where('outcomes.2.consequence', 'Prints and is handed out, but never shown in the gallery or the game.')
            ->where('undo', null)
        );
});

test('the queue skips a record another reviewer is on, and says so if you follow a link', function () {
    $taken = ($this->fursuit)(['name' => 'Taken']);
    $free = ($this->fursuit)(['name' => 'Free']);

    ($this->scoped)($this->second)->get(route('admin.fursuits.review.show', $taken))->assertSuccessful();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review'))
        ->assertRedirect(route('admin.fursuits.review.show', $free));

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $taken))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('presence.others.0.name', $this->second->name));
});

test('the queue hands out a busy record rather than claiming the queue is empty', function () {
    // The skip is a courtesy. With one record left and somebody on it, refusing to hand it
    // over would tell the reviewer there is nothing to do while the backlog is not empty.
    $only = ($this->fursuit)();

    ($this->scoped)($this->second)->get(route('admin.fursuits.review.show', $only))->assertSuccessful();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review'))
        ->assertRedirect(route('admin.fursuits.review.show', $only));
});

test('an empty queue lands on the list and says so', function () {
    ($this->fursuit)(['status' => Approved::$name]);

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review'))
        ->assertRedirect(route('admin.fursuits.index'))
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
        ->post(route('admin.fursuits.approve', [$fursuit, 'queue' => 1]))
        ->assertRedirect(route('admin.fursuits.review.show', $next));

    // Without the flag the same endpoint keeps the reviewer on record pages.
    ($this->scoped)($this->reviewer)
        ->post(route('admin.fursuits.approve', $next))
        ->assertRedirect(route('admin.fursuits.index'));
});

test('a publication block approves the badge and closes only the public surfaces', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
        'reason' => 'artwork',
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

test('the publication mail carries the consequence once, and only offers a resubmission that is possible', function () {
    /*
     * The reason says what we found; the mail says what follows. Both halves are asserted here because
     * the interesting part is the conditional: "you may resubmit" is an invitation to a disappointment
     * once the card is through the printer, and the attendee cannot tell which side of that line they
     * are on from the mail.
     *
     * Rendered rather than inspected line by line - that is what catches a broken blade, and the whole
     * point of the shared template is that the band, the finding and the button reach the inbox.
     */
    $fursuit = ($this->fursuit)();
    // The factory stamps `printed_at` on every badge, so "not printed yet" has to be stated.
    $fursuit->badges()->sole()->forceFill(['printed_at' => null, 'printing_locked_at' => null])->saveQuietly();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
        'reason' => 'fetish',
        'custom_reason' => 'We determined that your submission contains adult or fetish related items.',
    ]);

    ($this->deliver)();

    Notification::assertSentTo($fursuit->user, FursuitPublicationBlockedNotification::class,
        function (FursuitPublicationBlockedNotification $mail) use ($fursuit) {
            $message = $mail->toMail($fursuit->user);
            $html = $message->render();

            expect($message->subject)->toBe('"Fluffy" - badge approved, but not in the gallery')
                ->and($message->viewData['band'])->toBe('Approved, not published')
                ->and($message->viewData['tone'])->toBe('warn');

            return str_contains($html, 'We determined that your submission contains adult or fetish related items.')
                && str_contains($html, 'has been revoked')
                && str_contains($html, 'until we print your badge')
                && str_contains($html, 'Send a different photo')
                // The guidelines sentence carries the general rule; the finding above carries the
                // specific one. Both, in that order.
                && str_contains($html, 'did not meet the guidelines for publication');
        });

    // Same verdict on a badge that has already been printed: the finding and the revocation stand, the
    // offer to resubmit is replaced by what is actually possible.
    $printed = ($this->fursuit)(['name' => 'Already printed']);
    $printed->badges()->sole()->forceFill(['printed_at' => now(), 'printing_locked_at' => now()])->saveQuietly();

    Notification::fake();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $printed), [
        'reason' => 'artwork',
        'custom_reason' => 'We determined that your submission is artwork rather than a photo of a costume.',
    ]);

    ($this->deliver)();

    Notification::assertSentTo($printed->user, FursuitPublicationBlockedNotification::class,
        function (FursuitPublicationBlockedNotification $mail) use ($printed) {
            $html = $mail->toMail($printed->user)->render();

            return str_contains($html, 'has been revoked')
                && str_contains($html, 'already been printed')
                && str_contains($html, 'order a new badge')
                && ! str_contains($html, 'Send a different photo');
        });
});

test('a blocked fursuit stays out of the gallery even if the attendee flips the switch back on', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
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

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    ($this->scoped)($this->reviewer)->delete(route('admin.fursuits.unblock-publication', $fursuit))
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

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.approve', $fursuit))->assertRedirect();

    expect($fursuit->fresh()->isPublicationBlocked())->toBeFalse();
});

test('a second block is not offered while one stands, and a lift is', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    $outcomes = collect(
        ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $fursuit))
            ->viewData('page')['props']['outcomes']
    )->keyBy('value');

    expect($outcomes['publication_blocked']['available'])->toBeFalse()
        ->and($outcomes['publication_blocked']['unavailableReason'])
        ->toContain('already blocked from the gallery')
        // Approving is still available, because that is how the block is cleared.
        ->and($outcomes['approved']['available'])->toBeTrue();

    /*
     * And no second surface offers a way out of it either: the record page carries no review actions,
     * so clearing a block means approving the record in the queue - which is offered above, and which
     * does mail the attendee.
     */
    $actions = collect(
        actingAs($this->reviewer)->get(route('admin.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->pluck('name');

    expect($actions)->not->toContain('unblock-publication', 'approve', 'reject', 'block-publication');
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
    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit))
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

test('the block button is not offered when nothing was requested, and its key folds into Approve', function () {
    /*
     * Two buttons that do the same thing is a worse surface than one, so the block is simply not
     * there - but `g` is the key a reviewer reaches for on digital art, so it lands on Approve
     * instead of doing nothing.
     */
    $fursuit = ($this->fursuit)(['published' => false, 'catch_em_all' => false]);

    $outcomes = collect(
        ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $fursuit))
            ->viewData('page')['props']['outcomes']
    )->keyBy('value');

    expect($outcomes->keys()->all())->toBe(['approved', 'rejected'])
        ->and($outcomes['approved']['shortcuts'])->toBe(['a', 'g'])
        ->and($outcomes['approved']['consequence'])
        ->toBe('Prints and is handed out. The attendee asked for neither the gallery nor the game, so there is nothing to publish.');

    // The endpoint still answers, because an older tab or a typed URL must not create a block
    // nobody asked for.
    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit))
        ->assertRedirect();

    expect($fursuit->fresh()->isPublicationBlocked())->toBeFalse();
});

test('wanting only the game is still a request worth blocking', function () {
    // Publication is two surfaces. Reading only `published` would silently approve a fursuit
    // that asked to be catchable.
    $fursuit = ($this->fursuit)(['published' => false, 'catch_em_all' => true]);

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit))
        ->assertSessionHasErrors('custom_reason');

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
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

    $card = ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $fursuit))
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

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.reject', [$fursuit, 'queue' => 1]), [
        'custom_reason' => 'Wrong for the wrong reasons.',
    ])->assertRedirect();

    expect($fursuit->fresh()->status)->toBeInstanceOf(Rejected::class);

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.review.undo'))
        ->assertRedirect(route('admin.fursuits.review.show', $fursuit))
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Decision undone',
            'body' => 'Took back "Rejected, must be fixed" on Fluffy. Nothing was sent to the attendee.',
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

test('the undo bar sits on the record it applies to, not on the next one', function () {
    /*
     * Context matters more than reach here. The bar used to travel with the reviewer, so
     * "Approved on Fluffy - undo" sat above a different animal's photo: taking a verdict back
     * meant trusting a name instead of looking at what was being restored. Now the queue moves on
     * clean and the Back button is the way to the record, where the bar is.
     */
    $decided = ($this->fursuit)(['name' => 'Judged']);
    $next = ($this->fursuit)(['name' => 'Next']);

    ($this->scoped)($this->reviewer)
        ->post(route('admin.fursuits.approve', [$decided, 'queue' => 1]))
        ->assertRedirect(route('admin.fursuits.review.show', $next));

    // Nothing on the record the reviewer was carried to.
    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $next))
        ->assertInertia(fn (Assert $page) => $page->where('undo', null));

    // And it is there on the one they decided, naming the verdict and counting down.
    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $decided))
        ->assertInertia(fn (Assert $page) => $page
            ->where('undo.fursuit', 'Judged')
            ->where('undo.fursuitId', $decided->id)
            ->where('undo.outcome', 'Approved')
            ->where('undo.url', route('admin.fursuits.review.undo'))
            ->where('undo.expiresAt', fn ($at) => $at !== null)
        );
});

test('the panel shows the gallery variant, not the print master', function () {
    /*
     * The master is archival - print-sized, routinely over a megabyte - and the queue was pulling
     * one per record to fill a column. GenerateFursuitWebpJob renders the gallery variants when
     * the photo is submitted, so both surfaces read those: the row takes the 500px thumbnail and
     * the review page the 1080x1920 webp.
     */
    $fursuit = ($this->fursuit)();
    $fursuit->forceFill([
        'image_webp' => 'gallery/fursuits/fluffy.webp',
        'image_thumb' => 'gallery/fursuits/fluffy-thumb.webp',
    ])->save();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $fursuit))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fursuit.image', fn ($url) => str_contains((string) $url, 'fluffy.webp'))
        );

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.cells.image', fn ($url) => str_contains((string) $url, 'fluffy-thumb.webp'))
        );

    /*
     * A render that has not landed yet falls back to the master rather than to an empty frame: the
     * variant is derived data and a reviewer still has to see something to judge. The fixture stamps
     * renders on, so this case nulls them again - which is exactly the state of the seconds after an
     * upload.
     */
    $fresh = ($this->fursuit)(['name' => 'Not rendered yet', 'image' => 'fursuits/raw.jpg']);
    $fresh->forceFill(['image_webp' => null, 'image_thumb' => null])->saveQuietly();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $fresh))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fursuit.image', fn ($url) => str_contains((string) $url, 'fursuits/raw.jpg'))
        );
});

test('a record whose render is still queued says so and is held out of the queue', function () {
    /*
     * The seconds between an upload and GenerateFursuitWebpJob: the row exists, the photo does
     * not exist in any form a page should show. Handing that to a reviewer means a verdict on
     * a picture nobody saw, so the queue walks past it and the page says why.
     */
    $rendered = ($this->fursuit)(['name' => 'Rendered']);
    $processing = ($this->fursuit)(['name' => 'Still processing']);
    $processing->forceFill(['image_webp' => null, 'image_thumb' => null])->saveQuietly();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review'))
        ->assertRedirect(route('admin.fursuits.review.show', $rendered));

    // Arriving by link still works - it just says what is missing rather than showing the master.
    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $processing))
        ->assertInertia(fn (Assert $page) => $page->where('fursuit.imageProcessing', true));

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.show', $processing))
        ->assertInertia(fn (Assert $page) => $page->where('fursuit.imageProcessing', true));

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $rendered))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fursuit.imageProcessing', false)
            // And the count matches what the queue would actually hand out.
            ->where('queue.remaining', 1)
        );
});

test('a render that never lands stops hiding the record once the grace window passes', function () {
    /*
     * A file GD refuses to decode is logged and never retried, and an imported row never had a
     * job at all. Holding those back forever would swallow the submission, so after the grace
     * window the record comes back with its master photo, exactly as before.
     */
    $stuck = ($this->fursuit)(['name' => 'Never rendered', 'image' => 'fursuits/raw.jpg']);
    $stuck->forceFill(['image_webp' => null, 'image_thumb' => null])->saveQuietly();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review'))
        ->assertRedirect(route('admin.fursuits.index'));

    $this->travel(Fursuit::IMAGE_RENDER_GRACE_MINUTES + 1)->minutes();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review'))
        ->assertRedirect(route('admin.fursuits.review.show', $stuck));

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $stuck))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fursuit.imageProcessing', false)
            ->where('fursuit.image', fn ($url) => str_contains((string) $url, 'fursuits/raw.jpg'))
        );

    $this->travelBack();
});

test('a verdict on a record that never rendered does not put it back into processing', function () {
    /*
     * The render clock is the photo's, not the row's. It used to read `updated_at`, so
     * approving a record whose webp had failed for good bumped that column and the page the
     * reviewer landed on replaced the photo they had just judged with "still processing" -
     * for another grace window, and out of the queue with it.
     */
    $stuck = ($this->fursuit)(['name' => 'Never rendered', 'image' => 'fursuits/raw.jpg']);
    $stuck->forceFill(['image_webp' => null, 'image_thumb' => null])->saveQuietly();

    $this->travel(Fursuit::IMAGE_RENDER_GRACE_MINUTES + 1)->minutes();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.approve', $stuck))
        ->assertRedirect();

    expect($stuck->fresh()->imageRenderPending())->toBeFalse();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.show', $stuck))
        ->assertInertia(fn (Assert $page) => $page->where('fursuit.imageProcessing', false));

    $this->travelBack();
});

test('replacing the photo restarts the render clock', function () {
    // The other half of the same rule: a new photo is genuinely in flight again, whatever
    // the record's own age.
    $fursuit = ($this->fursuit)();

    $this->travel(Fursuit::IMAGE_RENDER_GRACE_MINUTES + 1)->minutes();

    $fursuit->update(['image' => 'fursuits/replacement.jpg']);

    expect($fursuit->fresh()->imageRenderPending())->toBeTrue();

    $this->travel(Fursuit::IMAGE_RENDER_GRACE_MINUTES + 1)->minutes();

    expect($fursuit->fresh()->imageRenderPending())->toBeFalse();

    $this->travelBack();
});

test('a submitted photo is queued for its gallery variants straight away', function () {
    // The admin surfaces read the variants, so they have to exist without anybody visiting the
    // gallery first. FursuitObserver dispatches on create and again whenever the photo changes.
    Queue::fake();

    $fursuit = Fursuit::factory()->create([
        'event_id' => $this->event->id,
        'species_id' => $this->species->id,
        'status' => Pending::$name,
        'image' => 'fursuits/first.jpg',
        'image_webp' => null,
    ]);

    Queue::assertPushed(GenerateFursuitWebpJob::class);

    Queue::fake();

    $fursuit->refresh();
    $fursuit->update(['image' => 'fursuits/second.jpg']);

    Queue::assertPushed(GenerateFursuitWebpJob::class);

    // And the variant columns are dropped with the old photo, so nothing serves the previous
    // picture while the re-render is queued.
    expect($fursuit->fresh()->image_webp)->toBeNull()
        ->and($fursuit->fresh()->image_thumb)->toBeNull();
});

test('the back target points at the last record decided, and is not an undo', function () {
    /*
     * The left arrow navigates; the button on that record undoes. Keeping them apart is the point:
     * the arrow used to perform the undo, which erased a verdict on a record the reviewer could not
     * see. So the page ships a URL, not an action, and the verdict is still standing after the trip.
     */
    $decided = ($this->fursuit)(['name' => 'Judged']);
    $next = ($this->fursuit)(['name' => 'Next']);

    ($this->scoped)($this->reviewer)
        ->post(route('admin.fursuits.approve', [$decided, 'queue' => 1]))
        ->assertRedirect(route('admin.fursuits.review.show', $next));

    // On the record it moved to: a way back, and no undo bar.
    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $next))
        ->assertInertia(fn (Assert $page) => $page
            ->where('back.url', route('admin.fursuits.review.show', $decided))
            ->where('back.fursuit', 'Judged')
            ->where('back.outcome', 'Approved')
            ->where('undo', null)
        );

    // Following it changes nothing by itself - and there the undo bar is.
    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $decided))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            // No trip to the page you are already on.
            ->where('back', null)
            ->where('undo.fursuit', 'Judged')
        );

    expect($decided->fresh()->status)->toBeInstanceOf(Approved::class);

    // Nothing to go back to once the verdict has been announced, so the arrow stops offering a
    // page that can no longer act on it.
    ($this->deliver)();

    ($this->scoped)($this->reviewer)->get(route('admin.fursuits.review.show', $next))
        ->assertInertia(fn (Assert $page) => $page->where('back', null));
});

test('undo is refused once the attendee has been told', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.approve', $fursuit));

    ($this->deliver)();

    Notification::assertSentTo($fursuit->user, FursuitApprovedNotification::class);

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.review.undo'))
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

    ($this->scoped)($this->second)->post(route('admin.fursuits.approve', $theirs));
    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.approve', $mine));

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.review.undo'))->assertRedirect();

    expect($mine->fresh()->status)->toBeInstanceOf(Pending::class)
        ->and($theirs->fresh()->status)->toBeInstanceOf(Approved::class);
});

test('a superseded verdict is neither undoable nor announced', function () {
    // Two reviewers on one record, which presence makes unlikely and does not prevent.
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.approve', $fursuit));
    ($this->scoped)($this->second)->post(route('admin.fursuits.block-publication', $fursuit), [
        'custom_reason' => 'Not a costume.',
    ]);

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.review.undo'))
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

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.reject', $fursuit), [
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

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.reject', $rejected), [
        'custom_reason' => 'Against the Code of Conduct.',
    ]);
    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $blocked), [
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

    // The rejected one is dropped. A fursuit still waiting for a verdict is not - review
    // runs behind and the desk does not wait for it; BadgePrintQueueTest covers that pair.
    expect(BadgePrintQueue::queue(collect([$rejected->badges()->sole()]), $printer))->toBeNull();

    $batch = BadgePrintQueue::queue(collect([$blockedBadge->fresh()]), $printer);

    expect($batch)->not->toBeNull()
        ->and($batch->total_jobs)->toBe(1);
});

test('the decision row carries what undo needs and nothing the attendee did not get', function () {
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.approve', $fursuit));

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
    // The rejection wording tells the attendee to fix their badge, which is wrong for a badge that
    // is approved and being printed - so the lists are per outcome and do not share slugs.
    $rejectionKeys = FursuitReviewService::reasonSlugs(FursuitReviewOutcomeEnum::Rejected);
    $blockKeys = FursuitReviewService::reasonSlugs(FursuitReviewOutcomeEnum::PublicationBlocked);

    expect($rejectionKeys)->not->toContain('artwork', 'ai_generated', 'real_animal', 'fetish')
        ->and($blockKeys)->toContain('artwork', 'ai_generated', 'real_animal', 'no_costume', 'identifiable_human', 'fetish')
        // Every body is a finding and nothing more; the consequence is the notification's job, so
        // no reason string mentions the gallery or printing at all.
        ->and(collect(FursuitReviewService::reasonOptions(FursuitReviewOutcomeEnum::PublicationBlocked))
            ->firstWhere('value', 'artwork')['body'])
        ->toBe('We determined that your submission is artwork rather than a photo of a costume.');

    $fursuit = ($this->fursuit)();

    // A rejection slug is not a publication-block slug.
    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
        'reason' => 'drugs',
        'custom_reason' => 'Anything',
    ])->assertSessionHasErrors('reason');

    ($this->scoped)($this->reviewer)->post(route('admin.fursuits.block-publication', $fursuit), [
        'reason' => 'no_costume',
    ])->assertSessionHasErrors('custom_reason');

    expect($fursuit->fresh()->isPublicationBlocked())->toBeFalse();
});

test('an outsider reaches none of it', function () {
    $outsider = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);
    $fursuit = ($this->fursuit)();

    actingAs($outsider);

    get(route('admin.fursuits.review'))->assertForbidden();
    get(route('admin.fursuits.review.show', $fursuit))->assertForbidden();
    post(route('admin.fursuits.review.undo'))->assertForbidden();
    post(route('admin.fursuits.block-publication', $fursuit), ['custom_reason' => 'x'])->assertForbidden();
});
