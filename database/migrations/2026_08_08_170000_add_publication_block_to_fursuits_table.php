<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second axis of a review verdict: may this fursuit be published?
 *
 * Approval used to be one yes/no, which forced reviewers to reject a badge outright
 * whenever the photo was fine by the Code of Conduct but wrong for the gallery - digital
 * art rather than a suit, say. Rejection blocks the card, so the attendee lost their
 * badge over a gallery rule. The two verdicts are independent, so they get independent
 * storage: `status` still decides whether the card may be printed and handed out, and
 * this pair decides whether the fursuit may appear in the gallery and be caught in the
 * game.
 *
 * "publication", not "gallery": the block covers Catch-Em-All as well, which is a second
 * public surface fed by the same photo.
 *
 * `published` and `catch_em_all` stay the attendee's own switches. The block sits over
 * them, so lifting it restores what the attendee asked for instead of what a reviewer
 * happened to leave behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fursuits', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('fursuits', 'publication_blocked_at')) {
                $table->timestamp('publication_blocked_at')->nullable()->after('rejected_at');
            }

            if (SchemaGuard::missingColumn('fursuits', 'publication_block_reason')) {
                $table->text('publication_block_reason')->nullable()->after('publication_blocked_at');
            }
        });

        // The gallery and the game both ask "publishable?" on every listing, and the
        // review queue asks for the blocked ones. Both are a null check on this column.
        Schema::table('fursuits', function (Blueprint $table) {
            if (! SchemaGuard::hasIndex('fursuits', 'fursuits_publication_blocked_at_index')) {
                $table->index('publication_blocked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fursuits', function (Blueprint $table) {
            if (SchemaGuard::hasIndex('fursuits', 'fursuits_publication_blocked_at_index')) {
                $table->dropIndex('fursuits_publication_blocked_at_index');
            }

            if (SchemaGuard::hasColumn('fursuits', 'publication_block_reason')) {
                $table->dropColumn('publication_block_reason');
            }

            if (SchemaGuard::hasColumn('fursuits', 'publication_blocked_at')) {
                $table->dropColumn('publication_blocked_at');
            }
        });
    }
};
