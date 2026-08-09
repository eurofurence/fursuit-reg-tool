<?php

use App\Services\FursuitReviewService;
use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reasons a reviewer picks from, owned by the desk rather than by a deploy.
 *
 * They were a PHP constant, so retiring a wording or adding one the RoC now covers meant a
 * pull request during the convention. Settings > Review Reasons edits them instead.
 *
 * Two fields per reason, which is the other half of the change: `keyword` is what the review
 * queue puts on a chip and `body` is the paragraph the attendee receives. The queue used to
 * render the paragraphs, so picking a reason meant reading eleven of them.
 *
 * Seeded here, and only while the table is empty, so a deploy brings the defaults to an
 * installation that has none without ever overwriting wording somebody has edited. The same
 * defaults are in ReviewReasonSeeder for `migrate:fresh --seed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingTable('review_reasons')) {
            Schema::create('review_reasons', function (Blueprint $table) {
                $table->id();

                // App\Enum\FursuitReviewOutcomeEnum. A reason belongs to one verdict: the
                // rejection wording tells the attendee to fix their badge, which is wrong for a
                // badge that is approved and being printed.
                $table->string('outcome');
                $table->string('slug');
                $table->string('keyword');
                $table->text('body');

                $table->unsignedInteger('sort_order')->default(0);
                // Retiring a reason keeps the history readable: decisions store the text they
                // sent, but the slug in a request log still resolves to something.
                $table->boolean('is_active')->default(true);

                $table->timestamps();

                $table->unique(['outcome', 'slug']);
                $table->index(['outcome', 'is_active', 'sort_order']);
            });
        }

        // Empty means "never seeded", not "the desk deleted everything": a desk that wants no
        // reasons at all deactivates them, and the settings screen refuses to leave an outcome
        // with none.
        if (DB::table('review_reasons')->exists()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach (FursuitReviewService::DEFAULT_REASONS as $outcome => $reasons) {
            $order = 0;

            foreach ($reasons as $slug => $reason) {
                $rows[] = [
                    'outcome' => $outcome,
                    'slug' => $slug,
                    'keyword' => $reason['keyword'],
                    'body' => $reason['body'],
                    'sort_order' => $order += 10,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('review_reasons')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reasons');
    }
};
