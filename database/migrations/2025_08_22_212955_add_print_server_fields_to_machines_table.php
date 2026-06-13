<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('machines', 'is_print_server')) {
                $table->boolean('is_print_server')->default(false)->after('should_discover_printers');
            }
            if (SchemaGuard::missingColumn('machines', 'qz_connection_status')) {
                $table->string('qz_connection_status')->default('disconnected')->after('is_print_server');
            }
            if (SchemaGuard::missingColumn('machines', 'qz_last_seen_at')) {
                $table->timestamp('qz_last_seen_at')->nullable()->after('qz_connection_status');
            }
            if (SchemaGuard::missingColumn('machines', 'pending_print_jobs_count')) {
                $table->unsignedInteger('pending_print_jobs_count')->default(0)->after('qz_last_seen_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('machines', 'is_print_server')) {
                $table->dropColumn('is_print_server');
            }
            if (SchemaGuard::hasColumn('machines', 'qz_connection_status')) {
                $table->dropColumn('qz_connection_status');
            }
            if (SchemaGuard::hasColumn('machines', 'qz_last_seen_at')) {
                $table->dropColumn('qz_last_seen_at');
            }
            if (SchemaGuard::hasColumn('machines', 'pending_print_jobs_count')) {
                $table->dropColumn('pending_print_jobs_count');
            }
        });
    }
};
