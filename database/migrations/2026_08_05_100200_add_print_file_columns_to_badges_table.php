<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rendering a badge and printing it used to be one indivisible step, so
     * every reprint re-rendered and nothing could be prepared in advance.
     * Holding the rendered file on the badge lets an event's PDFs all be
     * generated up front in one pass, leaving printing as a separate concern.
     *
     * print_file_hash covers the inputs to the render, so regeneration can be
     * skipped when nothing that affects the artwork has changed.
     */
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('badges', 'print_file_path')) {
                $table->string('print_file_path')->nullable()->after('picked_up_at');
            }

            if (SchemaGuard::missingColumn('badges', 'print_file_hash')) {
                $table->string('print_file_hash')->nullable()->after('print_file_path');
            }

            if (SchemaGuard::missingColumn('badges', 'print_file_renderer')) {
                $table->string('print_file_renderer')->nullable()->after('print_file_hash');
            }

            if (SchemaGuard::missingColumn('badges', 'print_file_generated_at')) {
                $table->timestamp('print_file_generated_at')->nullable()->after('print_file_renderer');
            }

            // Mirrored from the print job that produced this card, so unverified
            // cards can be listed without joining through print_jobs.
            if (SchemaGuard::missingColumn('badges', 'verified_print_at')) {
                $table->timestamp('verified_print_at')->nullable()->after('print_file_generated_at');
            }
        });

        Schema::table('badges', function (Blueprint $table) {
            if (! SchemaGuard::hasIndex('badges', ['print_file_generated_at'])) {
                $table->index('print_file_generated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (SchemaGuard::hasIndex('badges', ['print_file_generated_at'])) {
                $table->dropIndex(['print_file_generated_at']);
            }
        });

        Schema::table('badges', function (Blueprint $table) {
            $columns = [
                'print_file_path', 'print_file_hash', 'print_file_renderer',
                'print_file_generated_at', 'verified_print_at',
            ];

            foreach ($columns as $column) {
                if (SchemaGuard::hasColumn('badges', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
