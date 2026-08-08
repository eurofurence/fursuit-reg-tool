<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the current photo was stored, so "is the render still on its way?" has a clock of
 * its own.
 *
 * It used to be answered from `updated_at`, which every unrelated write bumps: approving a
 * fursuit whose webp never landed pushed the row straight back into "photo still
 * processing" for another fifteen minutes and out of the review queue with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingColumn('fursuits', 'image_uploaded_at')) {
            Schema::table('fursuits', function (Blueprint $table) {
                $table->timestamp('image_uploaded_at')->nullable()->after('image_thumb');
            });
        }

        /*
         * Existing rows: the photo is at least as old as the record, and any row whose
         * render was going to land has landed long ago. created_at therefore reads as
         * "settled", which is what these are. Guarded on NULL so a re-run converges.
         */
        DB::table('fursuits')
            ->whereNull('image_uploaded_at')
            ->whereNotNull('image')
            ->update(['image_uploaded_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (SchemaGuard::hasColumn('fursuits', 'image_uploaded_at')) {
            Schema::table('fursuits', function (Blueprint $table) {
                $table->dropColumn('image_uploaded_at');
            });
        }
    }
};
