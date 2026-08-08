<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per verdict a reviewer hands down, and the reason it can be taken back.
 *
 * Two things need this table.
 *
 * Undo. A reviewer working a queue at speed mis-clicks, and the only recovery used to be
 * to walk the state machine backwards by hand - which is not even legal for every edge -
 * after the attendee had already been mailed. A decision row carries the state the
 * fursuit was in before it (`restore`), so undo is a restore rather than a transition.
 *
 * The mail. Notifications are queued with a delay and dispatched against a decision id
 * rather than against the fursuit, so the job can check at send time whether the verdict
 * it is announcing is still the current one. That is what makes undo silent: inside the
 * window nothing has been sent yet, and the job exits when it wakes up.
 *
 * `notified_at` is therefore also the cutoff for undo. Once the attendee has been told,
 * the correction is a new verdict with its own mail, not an erasure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingTable('fursuit_review_decisions')) {
            Schema::create('fursuit_review_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fursuit_id')->constrained('fursuits')->cascadeOnDelete();
                // Nullable and nulled on delete: the log outlives the account, as the
                // activity log does.
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();

                // App\Enum\FursuitReviewOutcomeEnum.
                $table->string('outcome');
                // What the attendee is told. Required for the two negative outcomes,
                // never set for a plain approval.
                $table->text('reason')->nullable();

                /*
                 * The fursuit as it stood before this verdict: status, the two attendee
                 * switches, the publication block and the two state timestamps. JSON
                 * rather than columns because it is read only by undo, as a whole, and
                 * never queried.
                 */
                $table->json('restore');

                // When the mail comes due, and when it actually went out.
                $table->timestamp('notify_at')->nullable();
                $table->timestamp('notified_at')->nullable();

                $table->timestamp('undone_at')->nullable();
                $table->foreignId('undone_by_id')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();

                // "The current verdict for this fursuit" and "this reviewer's last
                // verdict", which are the two questions the queue asks on every load.
                $table->index(['fursuit_id', 'id']);
                $table->index(['reviewer_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fursuit_review_decisions');
    }
};
