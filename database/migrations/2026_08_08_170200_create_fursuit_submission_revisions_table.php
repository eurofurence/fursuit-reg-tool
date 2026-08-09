<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a fursuit looked like before somebody changed it.
 *
 * A reviewer judging a resubmission has to know what changed. Without this the queue shows
 * only the current photo, so a submission that comes back untouched - the attendee was told
 * their image is not a photo of a suit and sent the same file again - is indistinguishable
 * from one that was fixed, and gets judged from scratch every round.
 *
 * A snapshot, not a foreign-key graph: the species is stored by name as well as by id,
 * because the record has to still read correctly if the species row is renamed or removed.
 * `image` is the path the photo had at the time, which is why the attendee editor no longer
 * deletes the file it replaces - a history entry with no picture answers nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingTable('fursuit_submission_revisions')) {
            Schema::create('fursuit_submission_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fursuit_id')->constrained('fursuits')->cascadeOnDelete();

                // The superseded values.
                $table->string('name')->nullable();
                $table->unsignedBigInteger('species_id')->nullable();
                $table->string('species_name')->nullable();
                $table->string('image')->nullable();
                $table->boolean('published')->default(false);
                $table->boolean('catch_em_all')->default(false);

                // Whoever was signed in when the change was made: usually the attendee, and
                // sometimes the desk correcting a name. Null for a change made by a job or a
                // console command.
                $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();

                // The only query: this fursuit's history, newest first.
                $table->index(['fursuit_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fursuit_submission_revisions');
    }
};
