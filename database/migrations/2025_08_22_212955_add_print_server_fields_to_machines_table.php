<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->boolean('is_print_server')->default(false)->after('should_discover_printers');
            $table->string('qz_connection_status')->default('disconnected')->after('is_print_server');
            $table->timestamp('qz_last_seen_at')->nullable()->after('qz_connection_status');
            $table->unsignedInteger('pending_print_jobs_count')->default(0)->after('qz_last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn([
                'is_print_server',
                'qz_connection_status',
                'qz_last_seen_at',
                'pending_print_jobs_count'
            ]);
        });
    }
};