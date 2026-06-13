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
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('events', 'archival_notice')) {
                $table->text('archival_notice')->nullable()->after('order_ends_at');
            }
            if (SchemaGuard::missingColumn('events', 'catch_em_all_enabled')) {
                $table->boolean('catch_em_all_enabled')->default(true)->after('archival_notice');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('events', 'archival_notice')) {
                $table->dropColumn('archival_notice');
            }
            if (SchemaGuard::hasColumn('events', 'catch_em_all_enabled')) {
                $table->dropColumn('catch_em_all_enabled');
            }
        });
    }
};
