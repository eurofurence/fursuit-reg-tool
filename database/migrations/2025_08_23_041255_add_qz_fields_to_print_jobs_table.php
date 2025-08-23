<?php

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
            $table->string('qz_job_name')->nullable()->after('retry_count');
            $table->string('last_qz_status')->nullable()->after('qz_job_name');
            $table->text('last_qz_message')->nullable()->after('last_qz_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropColumn(['qz_job_name', 'last_qz_status', 'last_qz_message']);
        });
    }
};
