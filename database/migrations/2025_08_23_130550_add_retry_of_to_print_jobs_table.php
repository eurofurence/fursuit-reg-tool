<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('print_jobs', 'retry_of')) {
                $table->unsignedBigInteger('retry_of')->nullable()->after('last_qz_message');
                $table->foreign('retry_of')->references('id')->on('print_jobs')->onDelete('set null');
                $table->index('retry_of');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::hasForeignKeyOn('print_jobs', 'retry_of')) {
                $table->dropForeign(['retry_of']);
            }
            if (SchemaGuard::hasIndex('print_jobs', 'retry_of')) {
                $table->dropIndex(['retry_of']);
            }
            if (SchemaGuard::hasColumn('print_jobs', 'retry_of')) {
                $table->dropColumn('retry_of');
            }
        });
    }
};
