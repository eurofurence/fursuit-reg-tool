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
            if (SchemaGuard::missingColumn('print_jobs', 'qz_job_name')) {
                $table->string('qz_job_name')->nullable()->after('retry_count');
            }
            if (SchemaGuard::missingColumn('print_jobs', 'last_qz_status')) {
                $table->string('last_qz_status')->nullable()->after('qz_job_name');
            }
            if (SchemaGuard::missingColumn('print_jobs', 'last_qz_message')) {
                $table->text('last_qz_message')->nullable()->after('last_qz_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('print_jobs', 'qz_job_name')) {
                $table->dropColumn('qz_job_name');
            }
            if (SchemaGuard::hasColumn('print_jobs', 'last_qz_status')) {
                $table->dropColumn('last_qz_status');
            }
            if (SchemaGuard::hasColumn('print_jobs', 'last_qz_message')) {
                $table->dropColumn('last_qz_message');
            }
        });
    }
};
