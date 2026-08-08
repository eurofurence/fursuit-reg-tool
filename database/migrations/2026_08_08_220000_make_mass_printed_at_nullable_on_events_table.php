<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mass_printed_at` becomes nullable, and null means "the pre-print run has not happened yet".
 *
 * The column was created NOT NULL with `useCurrent()`, which forced every event into claiming a
 * mass print date and made a fresh event claim the run happened the moment it was created. The
 * public copy reads the date to decide between "collect on the first convention day" and "we print
 * yours on site, collect from the second", so that default told attendees the deadline had passed
 * before anyone had scheduled it.
 *
 * Null now reads the same as a date in the future: the run is still ahead, badges are in it. An
 * event whose run is done says so with a date, which is what the "deadline has passed" copy needs
 * in order to name it.
 *
 * `->change()` is unguarded on purpose (docs/migrations.md): it is idempotent by nature, unlike the
 * add-column operations that need SchemaGuard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('mass_printed_at')
                ->nullable()
                ->default(null)
                ->comment('When the pre-convention bulk print run happens; null means it is still ahead')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('mass_printed_at')
                ->nullable(false)
                ->useCurrent()
                ->change();
        });
    }
};
