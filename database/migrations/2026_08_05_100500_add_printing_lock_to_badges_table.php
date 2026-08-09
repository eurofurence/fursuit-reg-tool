<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freeze a badge once it has been committed to a print batch.
     *
     * Batches are immutable, and the artwork was rendered before the batch was
     * built. If the attendee could still edit their badge afterwards, the card
     * coming out of the printer would no longer match what the order says, and
     * nobody would notice until pickup.
     */
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('badges', 'printing_locked_at')) {
                $table->timestamp('printing_locked_at')->nullable()->after('verified_print_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('badges', 'printing_locked_at')) {
                $table->dropColumn('printing_locked_at');
            }
        });
    }
};
