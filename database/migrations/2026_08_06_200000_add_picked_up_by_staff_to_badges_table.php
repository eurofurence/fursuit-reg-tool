<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which staff member handed a badge over, so the POS can show a
 * volunteer how much of the queue they personally cleared.
 *
 * Nullable: handouts from the console or the admin panel have no staff member
 * behind them, and rows predating this column have none either.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingColumn('badges', 'picked_up_by_staff_id')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->foreignId('picked_up_by_staff_id')->nullable()->after('picked_up_at');
            });
        }

        if (! SchemaGuard::hasForeignKeyOn('badges', 'picked_up_by_staff_id')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->foreign('picked_up_by_staff_id')
                    ->references('id')
                    ->on('staff')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (SchemaGuard::hasForeignKeyOn('badges', 'picked_up_by_staff_id')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->dropForeign(['picked_up_by_staff_id']);
            });
        }

        if (SchemaGuard::hasColumn('badges', 'picked_up_by_staff_id')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->dropColumn('picked_up_by_staff_id');
            });
        }
    }
};
